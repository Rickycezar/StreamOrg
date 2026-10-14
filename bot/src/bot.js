/**
 * The bot itself, on Twitch's chat API so it shows as a chat bot:
 *
 * - it reads chat through an EventSub conduit whose one shard is this
 *   process's WebSocket, with a channel.chat.message subscription per
 *   channel (app token; allowed when the streamer granted channel:bot or
 *   made the bot a moderator);
 * - it replies with Send Chat Message, also with the app token;
 * - it posts the streamers' timed messages while they are live (Timers);
 * - it answers custom alerts, shoutouts and watch streaks with the replies
 *   set on the streamer's overlays (OverlayReplies); watch streaks arrive as chat
 *   notifications;
 * - it answers the giveaway, schedule and watch-time commands (builtins.js)
 *   in the streamer's language;
 * - everything it says goes through a queue per channel (ChatQueue), paced
 *   under Twitch's limits, dropping repeats of a command still waiting;
 * - it posts what the site asks it to (bot_outbox, announced with
 *   NOTIFY streamorg_bot, 'outbox');
 * - it records the channel's events (subscriptions, gifts, bits, raids,
 *   watch streaks, and follows where it may see them) in channel_events,
 *   and once a day compares the follower list of the channels an
 *   administrator chose, to notice unfollows (FollowerCheck);
 * - it records simple viewer statistics while channels are live (Tracker),
 *   only in channels whose chat it can read.
 *
 * Everything follows the database: it reloads when the app announces a
 * change (NOTIFY streamorg_bot) and every pollSeconds, and reports its
 * status for the admin page. Switched off, or without an account, it
 * stays out of every chat.
 */
import { CommandBook, Cooldowns, BUILTINS, MESSAGE_MAX, allowed, render } from './commands.js';
import { LOOKUPS, runLookup } from './builtins.js';
import { fromChat, fromFollow, fromNotice } from './events.js';
import { EventSubSocket } from './eventsub.js';
import { FollowerCheck, FOLLOWERS_SCOPE } from './followers.js';
import { ChatQueue } from './queue.js';
import { OverlayReplies } from './overlays.js';
import { Helix } from './helix.js';
import { Timers } from './timers.js';
import { Tracker } from './tracker.js';
import { refreshToken, validateToken } from './twitch.js';

const REPORT_EVERY = 30_000;
const VALIDATE_EVERY = 60 * 60_000;
const REFRESH_BEFORE = 5 * 60_000;
const RETRY_ACCESS_AFTER = 5 * 60_000;
const SEND_LIMIT = 20;
const SEND_WINDOW = 30_000;
const TIMERS_EVERY = 30_000;
const FOLLOWERS_EVERY = 60 * 60_000;
const FOLLOWERS_FIRST = 2 * 60_000;
const OUTBOX_TTL = 5 * 60_000;
const DROP_LOG_EVERY = 60_000;
const RETRY_FOLLOWS_AFTER = 30 * 60_000;
const SHOUTOUT_AGAIN_AFTER = 60_000;
const ALERT_AGAIN_AFTER = 5_000;
const CHAT = 'channel.chat.message';
const NOTICES = 'channel.chat.notification';
const FOLLOWS = 'channel.follow';

/**
 * The heartbeat's reply for the streamer and moderators: the bot's version
 * added after the reply's own text, so no rewording can drop or change it.
 */
export function withVersion(text, version) {
    const tag = ` · v${version}`;
    return String(text).slice(0, MESSAGE_MAX - tag.length) + tag;
}

/** A timezone Intl understands, or UTC. */
function validTimezone(zone) {
    try {
        new Intl.DateTimeFormat('en', { timeZone: zone });
        return zone;
    } catch {
        return 'UTC';
    }
}

/**
 * EventSub chat badges -> {broadcaster: '1', moderator: '1', …}. A Lead
 * Moderator shows the lead_moderator badge instead of moderator; they are
 * a moderator for every check.
 */
export function badgesOf(event) {
    const badges = {};
    for (const badge of event.badges || []) badges[badge.set_id] = badge.id;
    if ('lead_moderator' in badges && !('moderator' in badges)) badges.moderator = badges.lead_moderator;
    return badges;
}

export class Bot {
    /**
     * @param {{store: import('./store.js').Store, config: object, listen?: (onChange: () => void) => Promise<void>,
     *          SocketImpl?: typeof EventSubSocket, helix?: Helix, fetchImpl?: typeof fetch, trackEveryMs?: number, sendGapMs?: number}} deps
     */
    constructor({ store, config, listen = null, SocketImpl = EventSubSocket, helix = null, fetchImpl = fetch, trackEveryMs = 60_000, sendGapMs = 1100 }) {
        this.store = store;
        this.config = config;
        this.listen = listen;
        this.fetch = fetchImpl;
        this.startedAt = new Date();
        this.state = 'starting';
        this.detail = '';
        this.account = null;
        this.channels = new Map();
        this.subscriptions = new Map();
        this.notices = new Map();
        this.follows = new Map();
        this.followsFailed = new Map();
        this.prizeUrls = {};
        this.droppedAt = new Map();
        this.replies = new OverlayReplies();
        this.shoutedAt = new Map();
        this.access = new Map();
        this.commands = new CommandBook();
        this.cooldowns = new Cooldowns();
        this.timers = new Timers();
        this.queue = new ChatQueue({
            send: (channel, text, replyTo) => this.post(channel, text, replyTo),
            onDrop: (channel, reason) => this.dropped(channel, reason),
            gapMs: sendGapMs,
            limit: SEND_LIMIT,
            windowMs: SEND_WINDOW,
        });
        this.validatedAt = 0;
        this.intervals = [];
        this.reloading = null;
        this.reloadAgain = false;
        this.helix = helix || new Helix({ app: () => this.store.twitchApp(), fetchImpl });
        this.socket = new SocketImpl();
        this.tracker = new Tracker({
            store,
            helix: this.helix,
            botToken: () => this.botToken(),
            botId: () => this.account?.userId ?? null,
            intervalMs: trackEveryMs,
            fetchImpl,
        });
        this.followerCheck = new FollowerCheck({
            store,
            helix: this.helix,
            botToken: () => this.botToken(),
            botId: () => this.account?.userId ?? null,
            botScopes: () => this.account?.scopes || [],
            fetchImpl,
        });
        this.trackEveryMs = trackEveryMs;
        this.wire();
    }

    async start() {
        if (this.listen) {
            await this.listen((payload) => (payload === 'outbox' ? this.drainOutbox() : this.reload()))
                .catch((e) => console.error('listen:', e.message));
        }

        await this.reload();
        await this.report();

        this.intervals.push(setInterval(() => this.reload(), this.config.pollSeconds * 1000));
        this.intervals.push(setInterval(() => this.report(), REPORT_EVERY));
        this.intervals.push(setInterval(() => this.track(), this.trackEveryMs));
        this.intervals.push(setInterval(() => this.postTimers(), TIMERS_EVERY));
        this.intervals.push(setInterval(() => this.store.prune().catch(() => {}), 60 * 60_000));
        this.intervals.push(setInterval(() => this.checkFollowers(), FOLLOWERS_EVERY));
        this.intervals.push(setTimeout(() => this.checkFollowers(), FOLLOWERS_FIRST));
    }

    async stop() {
        this.intervals.forEach((t) => { clearInterval(t); clearTimeout(t); });
        this.socket.stop();
        this.state = 'stopped';
        this.detail = '';
        await this.report();
    }

    healthy() {
        return this.state !== 'starting' || Date.now() - this.startedAt.getTime() < 120_000;
    }

    get running() {
        return this.account?.enabled && this.account?.userId && this.socket.connected;
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
        const needs = this.config && this.config.requiresMigration;

        if (!(await this.store.ready(needs))) {
            const detail = needs
                ? `Waiting for StreamOrg migration ${needs} (release the site first)`
                : 'Waiting for the StreamOrg database migrations';
            if (this.detail !== detail) console.log(`StreamOrg bot: ${detail}`);
            return this.idle(detail);
        }

        const previous = this.account;
        this.account = await this.store.account();

        if (!this.account || !this.account.login || !this.account.userId) return this.idle('No bot account connected');
        if (!this.account.accessToken) return this.halt('error', 'The bot account must be connected again');
        if (!this.account.enabled) return this.idle('Switched off');
        if (!(await this.store.twitchApp())) return this.idle('Twitch is not configured in API settings');

        this.commands.load(await this.store.commands(), await this.store.customCommands());
        this.timers.load(await this.store.timers());
        this.replies.load(await this.store.overlayReplies());
        this.prizeUrls = await this.store.prizePageUrls?.() || {};

        const channels = await this.store.channels();
        this.channels = new Map(channels.map((c) => [c.twitchId, { ...c, locale: c.locale || 'en', timezone: validTimezone(c.timezone || 'UTC') }]));
        this.followReadable();

        if (previous && previous.userId !== this.account.userId) {
            this.subscriptions.clear();
            this.notices.clear();
            this.follows.clear();
        }

        if (Date.now() - this.validatedAt > VALIDATE_EVERY) await this.validate();

        if (this.socket.stopped) {
            this.setState('connecting', `Connecting as ${this.account.login}`);
            this.socket.start();
            return;
        }

        if (this.socket.connected) {
            await this.syncSubscriptions();
            await this.drainOutbox();
        }
    }

    idle(detail) {
        return this.halt('idle', detail);
    }

    halt(state, detail) {
        this.socket.stop();
        this.tracker.setChannels([]);
        this.setState(state, detail);
    }

    /** The conduit the socket serves: the one remembered, one already on the app, or a new one. */
    async conduit() {
        const listed = await this.helix.app('GET', 'eventsub/conduits');
        if (listed.status !== 200) throw new Error(`conduits: ${listed.status} ${listed.data?.message || ''}`);

        const conduits = listed.data.data || [];
        let conduit = conduits.find((c) => c.id === this.account.conduitId) || conduits[0];

        if (!conduit) {
            const created = await this.helix.app('POST', 'eventsub/conduits', { body: { shard_count: 1 } });
            if (created.status !== 200) throw new Error(`conduit: ${created.status} ${created.data?.message || ''}`);
            conduit = created.data.data[0];
        }

        if (conduit.id !== this.account.conduitId) {
            await this.store.saveConduit(conduit.id);
            this.account.conduitId = conduit.id;
        }

        return conduit.id;
    }

    /** Points the conduit's shard at this socket's session (on every welcome). */
    async attach(sessionId) {
        const conduitId = await this.conduit();
        const result = await this.helix.app('PATCH', 'eventsub/conduits/shards', {
            body: { conduit_id: conduitId, shards: [{ id: '0', transport: { method: 'websocket', session_id: sessionId } }] },
        });

        if (result.status !== 202 || (result.data?.errors || []).length) {
            throw new Error(`shard: ${result.status} ${result.data?.errors?.[0]?.message || result.data?.message || ''}`);
        }

        await this.store.log(null, 'info', `Listening to chat as ${this.account.login}`);
        await this.syncSubscriptions(true);
    }

    /**
     * One channel.chat.message subscription per channel, and none for
     * channels the bot left. A channel where Twitch refuses the
     * subscription (no permission, not a moderator) is tried again later.
     */
    async syncSubscriptions(fresh = false) {
        const conduitId = this.account.conduitId;
        if (!conduitId) return;

        if (fresh || this.subscriptions.size === 0) this.subscriptions = await this.existingSubscriptions(conduitId);

        for (const [broadcasterId, sub] of [...this.subscriptions]) {
            if (!this.channels.has(broadcasterId) || sub.botId !== this.account.userId) {
                await this.helix.app('DELETE', 'eventsub/subscriptions', { query: { id: sub.id } });
                this.subscriptions.delete(broadcasterId);
            }
        }

        for (const channel of this.channels.values()) {
            const existing = this.subscriptions.get(channel.twitchId);

            if (existing) {
                await this.markAccess(channel, existing.id);
                continue;
            }

            if (channel.access === 'none' && Date.now() - channel.checkedAt < RETRY_ACCESS_AFTER) continue;

            await this.subscribe(channel, conduitId);
        }

        await this.syncNotices(conduitId, fresh);
        await this.syncFollows(conduitId, fresh);
        this.followReadable();
        this.setState('connected', this.summary());
    }

    /**
     * Chat notifications (subscriptions, gifts, raids, watch streaks…) in
     * every channel whose chat the bot reads: for the channel's events and
     * the watch-streak replies.
     */
    async syncNotices(conduitId, fresh) {
        if (fresh) this.notices = await this.existingSubscriptions(conduitId, NOTICES);

        const wanted = new Set([...this.channels.values()]
            .filter((c) => this.subscriptions.has(c.twitchId))
            .map((c) => c.twitchId));

        for (const [broadcasterId, sub] of [...this.notices]) {
            if (!wanted.has(broadcasterId) || sub.botId !== this.account.userId) {
                await this.helix.app('DELETE', 'eventsub/subscriptions', { query: { id: sub.id } });
                this.notices.delete(broadcasterId);
            }
        }

        for (const broadcasterId of wanted) {
            if (this.notices.has(broadcasterId)) continue;

            const result = await this.helix.app('POST', 'eventsub/subscriptions', {
                body: {
                    type: NOTICES,
                    version: '1',
                    condition: { broadcaster_user_id: broadcasterId, user_id: this.account.userId },
                    transport: { method: 'conduit', conduit_id: conduitId },
                },
            });

            if (result.status === 202) this.notices.set(broadcasterId, { id: result.data.data[0].id, botId: this.account.userId });
            else if (result.status === 409) this.notices = await this.existingSubscriptions(conduitId, NOTICES);
        }
    }

    /**
     * Who may see a channel's follows: the streamer, when their connection
     * allows it, else the bot when it is a moderator there with that
     * permission; null when nobody may.
     */
    followModerator(channel) {
        if (channel.scopes.includes(FOLLOWERS_SCOPE)) return channel.twitchId;
        if (channel.access === 'moderator' && (this.account?.scopes || []).includes(FOLLOWERS_SCOPE)) return this.account.userId;
        return null;
    }

    /** A channel.follow subscription in every readable channel whose follows the bot may see. */
    async syncFollows(conduitId, fresh) {
        if (fresh) this.follows = await this.existingSubscriptions(conduitId, FOLLOWS);

        const wanted = new Map();

        for (const channel of this.channels.values()) {
            const moderator = this.subscriptions.has(channel.twitchId) ? this.followModerator(channel) : null;
            if (moderator) wanted.set(channel.twitchId, moderator);
        }

        for (const [broadcasterId, sub] of [...this.follows]) {
            if (wanted.get(broadcasterId) !== sub.botId) {
                await this.helix.app('DELETE', 'eventsub/subscriptions', { query: { id: sub.id } });
                this.follows.delete(broadcasterId);
            }
        }

        for (const [broadcasterId, moderator] of wanted) {
            if (this.follows.has(broadcasterId)) continue;
            if (Date.now() - (this.followsFailed.get(broadcasterId) || 0) < RETRY_FOLLOWS_AFTER) continue;

            const result = await this.helix.app('POST', 'eventsub/subscriptions', {
                body: {
                    type: FOLLOWS,
                    version: '2',
                    condition: { broadcaster_user_id: broadcasterId, moderator_user_id: moderator },
                    transport: { method: 'conduit', conduit_id: conduitId },
                },
            });

            if (result.status === 202) {
                this.follows.set(broadcasterId, { id: result.data.data[0].id, botId: moderator });
                this.followsFailed.delete(broadcasterId);
            } else if (result.status === 409) {
                this.follows = await this.existingSubscriptions(conduitId, FOLLOWS);
            } else {
                this.followsFailed.set(broadcasterId, Date.now());
            }
        }
    }

    /** Statistics and timers only concern channels whose chat the bot can read. */
    followReadable() {
        this.tracker.setChannels([...this.channels.values()].filter((c) => this.subscriptions.has(c.twitchId)));
    }

    /** @returns {Promise<Map<string, {id: string, botId: string}>>} this conduit's subscriptions of a type, by broadcaster */
    async existingSubscriptions(conduitId, type = CHAT) {
        const found = new Map();
        let after;

        do {
            const page = await this.helix.app('GET', 'eventsub/subscriptions', { query: { type, after } });
            if (page.status !== 200) break;

            for (const sub of page.data.data || []) {
                if (sub.transport?.conduit_id === conduitId && sub.status === 'enabled') {
                    found.set(String(sub.condition.broadcaster_user_id), {
                        id: sub.id,
                        botId: String(sub.condition.user_id ?? sub.condition.moderator_user_id),
                    });
                }
            }

            after = page.data.pagination?.cursor;
        } while (after);

        return found;
    }

    async subscribe(channel, conduitId) {
        const result = await this.helix.app('POST', 'eventsub/subscriptions', {
            body: {
                type: CHAT,
                version: '1',
                condition: { broadcaster_user_id: channel.twitchId, user_id: this.account.userId },
                transport: { method: 'conduit', conduit_id: conduitId },
            },
        });

        if (result.status === 202) {
            const id = result.data.data[0].id;
            this.subscriptions.set(channel.twitchId, { id, botId: this.account.userId });
            await this.markAccess(channel, id);
            await this.store.log(channel.userId, 'info', `Reading #${channel.login} (${this.accessOf(channel)})`);
            return;
        }

        if (result.status === 409) {
            this.subscriptions = await this.existingSubscriptions(conduitId);
            return;
        }

        const reason = result.status === 403
            ? 'Twitch needs the streamer\'s permission for the bot, or the bot as a moderator'
            : `Twitch: ${result.status} ${result.data?.message || ''}`.trim();

        if (channel.access !== 'none') await this.store.log(channel.userId, 'warn', `Cannot read #${channel.login}: ${reason}`);

        channel.access = 'none';
        channel.checkedAt = Date.now();
        this.access.set(channel.twitchId, 'none');
        await this.store.setAccess(channel.userId, 'none', null, reason);
    }

    /** With the subscription allowed: by the streamer's permission when they granted channel:bot, else as a moderator. */
    accessOf(channel) {
        return channel.scopes.includes('channel:bot') ? 'permission' : 'moderator';
    }

    async markAccess(channel, subscriptionId) {
        const access = this.accessOf(channel);
        if (this.access.get(channel.twitchId) === access && channel.access === access) return;

        this.access.set(channel.twitchId, access);
        channel.access = access;
        await this.store.setAccess(channel.userId, access, subscriptionId);
    }

    /** The bot account's own token (for the chatter list where it moderates), refreshed when about to expire. */
    async botToken() {
        const account = await this.store.account();
        if (!account || !account.accessToken) return null;

        if (account.expiresAt - Date.now() < REFRESH_BEFORE) return this.refresh(account);

        return account.accessToken;
    }

    /** @returns {Promise<string|null>} the new access token */
    async refresh(account) {
        const app = await this.store.twitchApp();
        if (!app || !account.refreshToken) return null;

        const result = await refreshToken(app, account.refreshToken, this.fetch);
        if (result === null) return null;

        if (result.invalid) {
            await this.store.dropTokens();
            await this.store.log(null, 'error', 'Twitch no longer accepts the bot account: connect it again on the admin page');
            this.halt('error', 'The bot account must be connected again');
            return null;
        }

        await this.store.saveTokens(result.accessToken, result.refreshToken, result.expiresIn);

        return result.accessToken;
    }

    /** Twitch asks apps to validate user tokens hourly; a revoked one is refreshed, or given up. */
    async validate() {
        const token = await this.botToken();
        if (!token) return;

        const result = await validateToken(token, this.fetch);
        if (result === null) return;

        this.validatedAt = Date.now();

        if (result === false) {
            const account = await this.store.account();
            if (account) await this.refresh({ ...account, expiresAt: 0 });
        }
    }

    wire() {
        this.socket.on('welcome', (sessionId) => {
            this.attach(sessionId).catch((e) => this.setState('error', e.message));
        });

        this.socket.on('closed', () => {
            if (!this.socket.stopped) this.setState('connecting', 'Reconnecting to Twitch');
        });

        this.socket.on('notification', ({ type, event }) => {
            if (type === CHAT) this.onChat(event).catch((e) => console.error('chat:', e.message));
            if (type === NOTICES) this.onNotice(event).catch((e) => console.error('notice:', e.message));
            if (type === FOLLOWS) this.onFollow(event).catch((e) => console.error('follow:', e.message));
        });

        this.socket.on('revocation', (subscription) => {
            const broadcasterId = String(subscription.condition?.broadcaster_user_id || '');

            if (subscription.type === NOTICES) {
                this.notices.delete(broadcasterId);
                return;
            }

            if (subscription.type === FOLLOWS) {
                this.follows.delete(broadcasterId);
                this.followsFailed.set(broadcasterId, Date.now());
                return;
            }

            const channel = this.channels.get(broadcasterId);
            this.subscriptions.delete(broadcasterId);
            this.followReadable();

            if (channel) {
                channel.access = 'none';
                channel.checkedAt = 0;
                this.access.set(broadcasterId, 'none');
                this.store.setAccess(channel.userId, 'none', null, `Twitch withdrew the bot's access (${subscription.status})`).catch(() => {});
                this.store.log(channel.userId, 'warn', `Twitch withdrew the bot's access to #${channel.login}`).catch(() => {});
            }
        });
    }

    async track() {
        if (!this.account?.enabled || this.channels.size === 0) return;
        await this.tracker.tick().catch((e) => console.error('tracker:', e.message));
    }

    /** A chat message: counted for the statistics, and answered when it calls a command. */
    async onChat(event) {
        const channel = this.channels.get(String(event.broadcaster_user_id));
        if (!channel || String(event.chatter_user_id) === this.account?.userId) return;

        const user = {
            id: String(event.chatter_user_id),
            login: event.chatter_user_login,
            displayName: event.chatter_user_name || event.chatter_user_login,
            badges: badgesOf(event),
        };

        this.tracker.countMessage(channel.twitchId, { id: user.id, login: user.login, name: user.displayName });
        if (this.tracker.isLive(channel.twitchId)) this.timers.countMessage(channel.userId);

        const cheer = fromChat(event);
        if (cheer) await this.record(channel, cheer);

        const text = event.message?.text || '';
        if (await this.answer({ channel, id: event.message_id, text, user })) return;
        await this.alerted(channel, event.message_id, text, user);
        await this.shoutout(channel, text, user);
    }

    /**
     * Runs the command a chat message calls, if any, within its permission
     * and cooldown.
     *
     * @returns {Promise<boolean>} whether the message called one of the bot's commands
     */
    async answer({ channel, id, text, user }) {
        if (!this.account) return false;

        const locale = channel.locale || 'en';
        const command = this.commands.match(channel.userId, this.account.prefix, text, locale);
        if (!command) return false;
        if (!allowed(command.permission, user.badges)) return true;

        const privileged = 'broadcaster' in user.badges || 'moderator' in user.badges;
        if (!privileged && !this.cooldowns.take(`${channel.userId}:${command.code}:${command.trigger}`, command.cooldown)) return true;

        const texts = LOOKUPS.includes(command.code)
            ? await runLookup(command.code, {
                store: this.store,
                channel,
                user,
                text,
                command,
                prefix: this.account.prefix,
                builtins: this.commands.builtinsFor(channel.userId, locale),
                prizeUrl: this.prizeUrls[locale] || this.prizeUrls.en || Object.values(this.prizeUrls)[0] || null,
            })
            : [render(command.response, (BUILTINS[command.code] || BUILTINS.custom)({
                channel: channel.login,
                user,
                text,
                uptimeMs: Date.now() - this.startedAt.getTime(),
            }))];

        if (texts.length === 0) return true;

        if (command.code === 'heartbeat' && privileged) texts[0] = withVersion(texts[0], this.config.version);

        if (await this.say(channel, texts, id, { key: `command:${command.code}:${command.trigger}` })) {
            await this.store.log(channel.userId, 'info', `Answered ${this.account.prefix}${command.trigger}`);
        }

        return true;
    }

    /** A custom alert fired from chat: each alert overlay's reply (once every few seconds per command). */
    async alerted(channel, id, text, user) {
        for (const alert of this.replies.alert(channel.userId, text, user.badges)) {
            const key = `${channel.userId}:alert:${alert.command}:${alert.reply}`;
            const now = Date.now();
            if (now - (this.shoutedAt.get(key) || 0) < ALERT_AGAIN_AFTER) continue;
            this.shoutedAt.set(key, now);

            if (await this.say(channel, render(alert.reply, { user: user.displayName, channel: channel.login }), id, { key: `alert:${alert.command}` })) {
                await this.store.log(channel.userId, 'info', `Answered the alert !${alert.command}`);
            }
        }
    }

    /**
     * A shoutout with the shoutout overlay's commands: the reply set on the
     * overlay, with the channel's name, category and title (once a minute
     * per channel shouted out).
     */
    async shoutout(channel, text, user) {
        const asked = this.replies.shoutout(channel.userId, text, user.badges);
        if (!asked) return;

        if (!asked.allowed) {
            await this.store.log(channel.userId, 'info', `Ignored !${asked.command} ${asked.target}: whoever typed it is not one of the roles set on the shoutout overlay`);
            return;
        }

        const key = `${channel.userId}:${asked.target}`;
        const now = Date.now();
        if (now - (this.shoutedAt.get(key) || 0) < SHOUTOUT_AGAIN_AFTER) return;
        this.shoutedAt.set(key, now);

        const found = await this.helix.app('GET', 'users', { query: { login: asked.target } });
        const target = found.data?.data?.[0];

        if (found.status !== 200 || !target) {
            await this.store.log(channel.userId, 'warn', found.status === 200
                ? `Shoutout to ${asked.target}: no Twitch channel with that name`
                : `Shoutout to ${asked.target}: Twitch did not answer (${found.status})`);
            return;
        }

        const info = await this.helix.app('GET', 'channels', { query: { broadcaster_id: target.id } });
        const live = info.data?.data?.[0] || {};

        const values = {
            user: user.displayName,
            name: target.display_name || target.login,
            login: target.login,
            category: live.game_name || '',
            title: live.title || '',
        };

        if (await this.say(channel, render(asked.reply, values), null, { key: `shoutout:${target.login}` })) {
            await this.store.log(channel.userId, 'info', `Answered a shoutout to ${target.login}`);
        }
    }

    /** A chat notification: recorded as an event; a watch streak shared in chat gets the overlay's reply. */
    async onNotice(event) {
        const channel = this.channels.get(String(event.broadcaster_user_id));
        if (!channel) return;

        const recorded = fromNotice(event);
        if (recorded) await this.record(channel, recorded);

        if (event.notice_type !== 'watch_streak' || !event.watch_streak) return;

        const count = Number(event.watch_streak.streak_count) || 0;
        const reply = this.replies.streak(channel.userId, count);
        if (!reply) return;

        const values = { user: event.chatter_user_name || event.chatter_user_login || '', streak: count };

        if (await this.say(channel, render(reply, values))) {
            await this.store.log(channel.userId, 'info', `Answered a watch streak of ${count}`);
        }
    }

    /** Posts the timed messages that are due in live channels the bot can read. */
    async postTimers(now = Date.now()) {
        if (!this.account?.enabled || !this.socket.connected) return;

        const byUser = new Map([...this.channels.values()].map((c) => [c.userId, c]));
        const live = (userId) => {
            const channel = byUser.get(userId);
            return Boolean(channel && this.subscriptions.has(channel.twitchId) && this.tracker.isLive(channel.twitchId));
        };

        for (const timer of this.timers.due(live, now)) {
            const channel = byUser.get(timer.userId);

            if (await this.say(channel, render(timer.message, { channel: channel.login }), null, { key: `timer:${timer.id}` })) {
                await this.store.timerSent(timer.id).catch(() => {});
                await this.store.log(channel.userId, 'info', 'Posted a timed message').catch(() => {});
            }
        }
    }

    /** A follow in a channel whose follows the bot may see. */
    async onFollow(event) {
        const channel = this.channels.get(String(event.broadcaster_user_id));
        if (!channel) return;

        const follow = fromFollow(event);
        await this.record(channel, follow);
        await this.store.rememberFollower?.(channel.userId, { id: follow.actorId, login: follow.actorLogin, name: follow.actorName, followedAt: follow.at });
    }

    async record(channel, event) {
        await this.store.recordEvent?.(channel.userId, event).catch((e) => console.error('event:', e.message));
    }

    /** Posts what the site asked the bot to post (the giveaway page's "post in chat"), in channels it reads. */
    async drainOutbox() {
        if (!this.account?.enabled || !this.socket.connected || !this.store.takeOutbox) return;

        const byUser = new Map([...this.channels.values()].map((c) => [c.userId, c]));

        for (const row of await this.store.takeOutbox().catch(() => [])) {
            const channel = byUser.get(row.userId);

            if (!channel || !this.subscriptions.has(channel.twitchId)) {
                await this.store.log(row.userId, 'warn', 'Could not post the message from StreamOrg: the bot is not reading this chat');
                continue;
            }

            this.say(channel, row.message, null, { key: `outbox:${row.id}`, ttlMs: OUTBOX_TTL }).then((sent) => {
                if (sent) this.store.log(channel.userId, 'info', 'Posted a message from StreamOrg').catch(() => {});
            });
        }
    }

    /** The daily follower-list check, for the channels an administrator chose. */
    async checkFollowers() {
        if (!this.account?.enabled || this.channels.size === 0) return;
        await this.followerCheck.tick([...this.channels.values()]).catch((e) => console.error('followers:', e.message));
    }

    /**
     * Says something in a channel: through the queue, paced under Twitch's
     * limits. Several texts go out as consecutive messages.
     *
     * @param {string|string[]} text
     * @param {{key?: ?string, ttlMs?: number}} options key: a repeat waiting with the same key is dropped
     * @returns {Promise<boolean>} whether it was posted
     */
    say(channel, text, replyTo = null, { key = null, ttlMs = 60_000 } = {}) {
        return this.queue.push(channel, { texts: Array.isArray(text) ? text : [text], replyTo, key, ttlMs });
    }

    /** A reply the queue dropped: noted in the channel's activity, at most once a minute. */
    dropped(channel, reason) {
        if (reason === 'duplicate') return;

        const now = Date.now();
        if (now - (this.droppedAt.get(channel.twitchId) || 0) < DROP_LOG_EVERY) return;
        this.droppedAt.set(channel.twitchId, now);

        const why = reason === 'full' ? 'too many replies waiting' : 'it waited too long to be posted';
        this.store.log(channel.userId, 'warn', `Skipped a reply to keep under Twitch's chat limits (${why})`).catch(() => {});
    }

    /** Sends one chat message as the bot (the queue decides when). */
    async post(channel, text, replyTo = null) {
        const result = await this.helix.app('POST', 'chat/messages', {
            body: {
                broadcaster_id: channel.twitchId,
                sender_id: this.account.userId,
                message: String(text).replace(/[\r\n]+/g, ' ').slice(0, MESSAGE_MAX),
                ...(replyTo ? { reply_parent_message_id: replyTo } : {}),
            },
        });

        const sent = result.status === 200 && result.data?.data?.[0]?.is_sent;

        if (!sent) {
            const reason = result.data?.data?.[0]?.drop_reason?.message || result.data?.message || `status ${result.status}`;
            await this.store.log(channel.userId, 'warn', `Twitch did not post the reply: ${reason}`);
        }

        return Boolean(sent);
    }

    summary() {
        const reading = [...this.channels.values()].filter((c) => this.subscriptions.has(c.twitchId)).length;
        return `Connected as ${this.account?.login} · reading ${reading} of ${this.channels.size} channel(s)`;
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
