/**
 * The bot itself: keeps the chat connection, channels and commands in step
 * with the database, answers commands, and reports its status for the
 * admin page.
 *
 * It reloads when the app announces a change (NOTIFY streamorg_bot) and,
 * as a safety net, every pollSeconds. With the bot switched off, or no
 * account connected, it stays out of chat and only reports that.
 */
import { CommandBook, Cooldowns, BUILTINS, allowed, render } from './commands.js';
import { TwitchChat } from './chat.js';
import { refreshToken, validateToken } from './twitch.js';

const REPORT_EVERY = 30_000;
const VALIDATE_EVERY = 60 * 60_000;
const REFRESH_BEFORE = 5 * 60_000;

export class Bot {
    /** @param {{store: import('./store.js').Store, config: object, listen?: (onChange: () => void) => Promise<void>, ChatImpl?: typeof TwitchChat, fetchImpl?: typeof fetch}} deps */
    constructor({ store, config, listen = null, ChatImpl = TwitchChat, fetchImpl = fetch }) {
        this.store = store;
        this.config = config;
        this.listen = listen;
        this.fetch = fetchImpl;
        this.startedAt = new Date();
        this.state = 'starting';
        this.detail = '';
        this.account = null;
        this.channels = new Map();
        this.commands = new CommandBook();
        this.cooldowns = new Cooldowns();
        this.validatedAt = 0;
        this.timers = [];
        this.reloading = null;
        this.reloadAgain = false;
        this.chat = new ChatImpl({ credentials: () => this.credentials() });
        this.wire();
    }

    async start() {
        if (this.listen) await this.listen(() => this.reload()).catch((e) => console.error('listen:', e.message));

        await this.reload();
        await this.report();

        this.timers.push(setInterval(() => this.reload(), this.config.pollSeconds * 1000));
        this.timers.push(setInterval(() => this.report(), REPORT_EVERY));
        this.timers.push(setInterval(() => this.store.prune().catch(() => {}), 60 * 60_000));
    }

    async stop() {
        this.timers.forEach(clearInterval);
        this.chat.stop();
        this.state = 'stopped';
        this.detail = '';
        await this.report();
    }

    /** Whether the process is healthy enough to keep running (for the container health check). */
    healthy() {
        return this.state !== 'starting' || Date.now() - this.startedAt.getTime() < 120_000;
    }

    /** Reads everything again; overlapping calls fold into one more pass. */
    reload() {
        if (this.reloading) {
            this.reloadAgain = true;
            return this.reloading;
        }

        this.reloading = this.load()
            .catch((e) => this.setState('error', e.message))
            .finally(() => {
                this.reloading = null;
                if (this.reloadAgain) {
                    this.reloadAgain = false;
                    this.reload();
                }
            });

        return this.reloading;
    }

    async load() {
        if (!(await this.store.ready())) {
            this.chat.stop();
            return this.setState('idle', 'Waiting for the StreamOrg database migrations');
        }

        const previous = this.account;
        this.account = await this.store.account();

        if (!this.account || !this.account.login) {
            this.chat.stop();
            return this.setState('idle', 'No bot account connected');
        }

        if (!this.account.accessToken) {
            this.chat.stop();
            return this.setState('error', 'The bot account must be connected again');
        }

        if (!this.account.enabled) {
            this.chat.stop();
            return this.setState('idle', 'Switched off');
        }

        if (!(await this.store.twitchApp())) {
            this.chat.stop();
            return this.setState('idle', 'Twitch is not configured in API settings');
        }

        this.commands.load(await this.store.commands());

        const channels = await this.store.channels();
        this.channels = new Map(channels.map((c) => [c.login, c.userId]));
        this.chat.setChannels(this.channels.keys());

        if (previous && previous.login !== this.account.login && !this.chat.stopped) {
            this.chat.restart();
        } else if (this.chat.stopped) {
            this.setState('connecting', `Connecting as ${this.account.login}`);
            this.chat.start();
        }

        if (Date.now() - this.validatedAt > VALIDATE_EVERY) await this.validate();

        if (this.chat.connected) this.setState('connected', this.summary());
    }

    /** The login and a token fresh enough to use, refreshing it first when it is about to expire. */
    async credentials() {
        const account = await this.store.account();
        if (!account || !account.login || !account.accessToken || !account.enabled) return null;

        if (account.expiresAt - Date.now() < REFRESH_BEFORE) {
            const fresh = await this.refresh(account);
            if (!fresh) return null;
            return { login: account.login, token: fresh };
        }

        return { login: account.login, token: account.accessToken };
    }

    /** @returns {Promise<string|null>} the new access token */
    async refresh(account) {
        const app = await this.store.twitchApp();
        if (!app || !account.refreshToken) return null;

        const result = await refreshToken(app, account.refreshToken, this.fetch);

        if (result === null) {
            this.setState('error', 'Could not reach Twitch to refresh the token');
            return null;
        }

        if (result.invalid) {
            await this.store.dropTokens();
            await this.store.log(null, 'error', 'Twitch no longer accepts the bot account: connect it again on the admin page');
            this.chat.stop();
            this.setState('error', 'The bot account must be connected again');
            return null;
        }

        await this.store.saveTokens(result.accessToken, result.refreshToken, result.expiresIn);

        return result.accessToken;
    }

    /** Twitch asks apps to validate user tokens hourly; a revoked one is refreshed, or given up. */
    async validate() {
        const token = (await this.credentials())?.token;
        if (!token) return;

        const result = await validateToken(token, this.fetch);
        if (result === null) return;

        this.validatedAt = Date.now();

        if (result === false) {
            const account = await this.store.account();
            if (account && (await this.refresh({ ...account, expiresAt: 0 }))) this.chat.restart();
        }
    }

    wire() {
        this.chat.on('ready', () => {
            this.setState('connected', this.summary());
            this.store.log(null, 'info', `Connected to chat as ${this.account?.login}`).catch(() => {});
        });

        this.chat.on('closed', () => {
            if (!this.chat.stopped) this.setState('connecting', 'Reconnecting to chat');
        });

        this.chat.on('authFailed', async () => {
            const account = await this.store.account().catch(() => null);
            if (account) await this.refresh({ ...account, expiresAt: 0 });
        });

        this.chat.on('joined', (channel) => {
            const userId = this.channels.get(channel);
            if (userId === undefined) return;

            this.store.joined(userId).catch(() => {});
            this.store.log(userId, 'info', `Joined #${channel}`).catch(() => {});
            this.setState('connected', this.summary());
        });

        this.chat.on('notice', ({ channel, msgId, text }) => {
            const userId = this.channels.get(channel);
            if (userId === undefined || !msgId || !/^(msg_|bad_|no_permission)/.test(msgId)) return;

            this.store.channelError(userId, text).catch(() => {});
            this.store.log(userId, 'warn', `Twitch: ${text}`).catch(() => {});
        });

        this.chat.on('message', (message) => this.answer(message).catch((e) => console.error('answer:', e.message)));
    }

    /** Runs the command a chat message calls, if any, within its permission and cooldown. */
    async answer(message) {
        const userId = this.channels.get(message.channel);
        if (userId === undefined || !this.account) return;

        const command = this.commands.match(userId, this.account.prefix, message.text);
        if (!command || !allowed(command.permission, message.user.badges)) return;

        const privileged = 'broadcaster' in message.user.badges || 'moderator' in message.user.badges;
        if (!privileged && !this.cooldowns.take(`${userId}:${command.code}`, command.cooldown)) return;

        const values = BUILTINS[command.code]({
            channel: message.channel,
            user: message.user,
            uptimeMs: Date.now() - this.startedAt.getTime(),
        });

        if (this.chat.say(message.channel, render(command.response, values), message.id)) {
            await this.store.log(userId, 'info', `Answered ${this.account.prefix}${command.trigger}`);
        }
    }

    summary() {
        const joined = this.chat.joinedChannels.size;
        return `Connected as ${this.account?.login} · in ${joined} of ${this.channels.size} channel(s)`;
    }

    setState(state, detail) {
        const changed = state !== this.state || detail !== this.detail;
        this.state = state;
        this.detail = detail;
        if (changed) this.report();
    }

    async report() {
        await this.store.report(this.state, this.detail, this.config.version, this.startedAt).catch(() => {});
    }
}
