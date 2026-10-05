/**
 * Twitch chat over its IRC WebSocket (wss://irc-ws.chat.twitch.tv), with
 * Node's own WebSocket: one connection for every channel the bot is in.
 *
 * Emits:
 *   ready                         logged in
 *   joined   (channel)            the bot is in a channel
 *   message  ({channel, id, text, user: {login, displayName, badges}})
 *   notice   ({channel, msgId, text})
 *   closed   (reason)             connection lost; it reconnects by itself
 *   authFailed                    Twitch refused the token
 *
 * Keeps inside Twitch's limits for accounts that are not verified bots:
 * 20 joins per 10 seconds and 20 messages per 30 seconds.
 */
import { EventEmitter } from 'node:events';

const URL_DEFAULT = 'wss://irc-ws.chat.twitch.tv:443';
const MESSAGE_MAX = 500;

/**
 * One IRC line into its parts, IRCv3 tags included.
 *
 * @returns {{tags: Record<string, string>, prefix: string|null, command: string, params: string[]}|null}
 */
export function parseLine(line) {
    let rest = line.replace(/\r?\n$/, '');
    if (rest === '') return null;

    const tags = {};

    if (rest.startsWith('@')) {
        const space = rest.indexOf(' ');
        for (const pair of rest.slice(1, space).split(';')) {
            const eq = pair.indexOf('=');
            const key = eq === -1 ? pair : pair.slice(0, eq);
            tags[key] = eq === -1 ? '' : unescapeTag(pair.slice(eq + 1));
        }
        rest = rest.slice(space + 1);
    }

    let prefix = null;

    if (rest.startsWith(':')) {
        const space = rest.indexOf(' ');
        prefix = rest.slice(1, space);
        rest = rest.slice(space + 1);
    }

    const params = [];
    const trailing = rest.indexOf(' :');
    let head = rest;

    if (trailing !== -1) {
        head = rest.slice(0, trailing);
        params.push(rest.slice(trailing + 2));
    } else if (rest.startsWith(':')) {
        head = '';
        params.push(rest.slice(1));
    }

    const words = head.split(' ').filter(Boolean);
    const command = words.shift() || '';

    return { tags, prefix, command, params: [...words, ...params] };
}

/** IRCv3 tag values escape spaces, semicolons and backslashes. */
function unescapeTag(value) {
    return value.replace(/\\(.)/g, (_, ch) => ({ s: ' ', ':': ';', '\\': '\\', r: '\r', n: '\n' })[ch] ?? ch);
}

/** "broadcaster/1,subscriber/12" -> {broadcaster: '1', subscriber: '12'} */
export function parseBadges(value) {
    const badges = {};

    for (const part of String(value || '').split(',')) {
        if (!part) continue;
        const [name, version = ''] = part.split('/');
        badges[name] = version;
    }

    return badges;
}

/** Calls fn at most `limit` times per `windowMs`, queueing the rest. */
class Throttle {
    constructor(limit, windowMs, maxQueue = 100) {
        this.limit = limit;
        this.windowMs = windowMs;
        this.maxQueue = maxQueue;
        this.sent = [];
        this.queue = [];
        this.timer = null;
    }

    push(fn) {
        if (this.queue.length >= this.maxQueue) return false;
        this.queue.push(fn);
        this.drain();
        return true;
    }

    clear() {
        this.queue = [];
        clearTimeout(this.timer);
        this.timer = null;
    }

    drain() {
        const now = Date.now();
        this.sent = this.sent.filter((at) => now - at < this.windowMs);

        while (this.queue.length && this.sent.length < this.limit) {
            this.sent.push(now);
            this.queue.shift()();
        }

        if (this.queue.length && !this.timer) {
            const wait = this.windowMs - (now - this.sent[0]) + 50;
            this.timer = setTimeout(() => { this.timer = null; this.drain(); }, wait);
        }
    }
}

export class TwitchChat extends EventEmitter {
    /**
     * @param {{credentials: () => Promise<{login: string, token: string}|null>, url?: string, WebSocketImpl?: typeof WebSocket}} options
     */
    constructor({ credentials, url = URL_DEFAULT, WebSocketImpl = globalThis.WebSocket }) {
        super();
        this.credentials = credentials;
        this.url = url;
        this.WebSocketImpl = WebSocketImpl;
        this.socket = null;
        this.login = null;
        this.wanted = new Set();
        this.joinedChannels = new Set();
        this.stopped = true;
        this.attempt = 0;
        this.reconnectTimer = null;
        this.pingTimer = null;
        this.lastSeen = 0;
        this.joins = new Throttle(20, 10_000, 1000);
        this.messages = new Throttle(20, 30_000, 50);
    }

    get connected() {
        return this.socket !== null && this.login !== null && this.socket.readyState === 1;
    }

    /** Starts (or restarts) the connection; it then keeps itself up until stop(). */
    start() {
        this.stopped = false;
        this.open();
    }

    stop() {
        this.stopped = true;
        clearTimeout(this.reconnectTimer);
        clearInterval(this.pingTimer);
        this.joins.clear();
        this.messages.clear();

        if (this.socket) {
            try { this.socket.close(); } catch { }
        }

        this.socket = null;
        this.login = null;
        this.joinedChannels.clear();
    }

    /** Drops the connection and logs in again, with fresh credentials. */
    restart() {
        this.stop();
        this.start();
    }

    /** The channels to be in: joins the new ones, leaves the rest. */
    setChannels(logins) {
        const next = new Set([...logins].map((l) => String(l).toLowerCase()));

        for (const channel of this.wanted) {
            if (!next.has(channel)) {
                this.joinedChannels.delete(channel);
                if (this.connected) this.raw(`PART #${channel}`);
            }
        }

        const added = [...next].filter((c) => !this.wanted.has(c));
        this.wanted = next;

        if (this.connected) added.forEach((channel) => this.join(channel));
    }

    /** Says something in a channel, as a reply to a message when its id is given. */
    say(channel, text, replyTo = null) {
        const line = String(text).replace(/[\r\n]+/g, ' ').slice(0, MESSAGE_MAX);
        const tag = replyTo ? `@reply-parent-msg-id=${replyTo} ` : '';

        return this.messages.push(() => this.raw(`${tag}PRIVMSG #${channel} :${line}`));
    }

    async open() {
        clearTimeout(this.reconnectTimer);

        const creds = await this.credentials().catch(() => null);

        if (this.stopped) return;

        if (!creds) {
            this.scheduleReconnect('no credentials');
            return;
        }

        const socket = new this.WebSocketImpl(this.url);
        this.socket = socket;
        this.login = null;
        this.joinedChannels.clear();

        socket.addEventListener('open', () => {
            this.raw('CAP REQ :twitch.tv/tags twitch.tv/commands');
            this.raw(`PASS oauth:${creds.token}`);
            this.raw(`NICK ${creds.login}`);
        });

        socket.addEventListener('message', (event) => {
            this.lastSeen = Date.now();
            String(event.data).split('\r\n').forEach((line) => this.handle(line, creds.login));
        });

        socket.addEventListener('close', () => {
            if (this.socket !== socket) return;
            this.socket = null;
            this.login = null;
            clearInterval(this.pingTimer);
            this.emit('closed', 'connection closed');
            this.scheduleReconnect('closed');
        });

        socket.addEventListener('error', () => {
            try { socket.close(); } catch { }
        });
    }

    scheduleReconnect() {
        if (this.stopped) return;

        const delay = Math.min(60_000, 1000 * 2 ** Math.min(this.attempt, 6));
        this.attempt++;
        clearTimeout(this.reconnectTimer);
        this.reconnectTimer = setTimeout(() => this.open(), delay);
    }

    handle(line, login) {
        const msg = parseLine(line);
        if (!msg) return;

        switch (msg.command) {
            case 'PING':
                this.raw(`PONG :${msg.params[0] || 'tmi.twitch.tv'}`);
                break;

            case '001':
                this.login = login;
                this.attempt = 0;
                this.lastSeen = Date.now();
                this.keepAlive();
                this.emit('ready');
                this.wanted.forEach((channel) => this.join(channel));
                break;

            case 'JOIN': {
                const who = (msg.prefix || '').split('!')[0];
                const channel = (msg.params[0] || '').replace(/^#/, '');

                if (who === this.login && this.wanted.has(channel)) {
                    this.joinedChannels.add(channel);
                    this.emit('joined', channel);
                }
                break;
            }

            case 'PRIVMSG': {
                const channel = (msg.params[0] || '').replace(/^#/, '');
                const userLogin = (msg.prefix || '').split('!')[0];

                if (userLogin === this.login) break;

                this.emit('message', {
                    channel,
                    id: msg.tags.id || null,
                    text: msg.params[1] || '',
                    user: {
                        login: userLogin,
                        displayName: msg.tags['display-name'] || userLogin,
                        badges: parseBadges(msg.tags.badges),
                    },
                });
                break;
            }

            case 'NOTICE': {
                const channel = (msg.params[0] || '').replace(/^#/, '');
                const text = msg.params[1] || '';

                if (channel === '*' && /authentication failed|improperly formatted auth/i.test(text)) {
                    this.emit('authFailed', text);
                    try { this.socket?.close(); } catch { }
                    break;
                }

                this.emit('notice', { channel, msgId: msg.tags['msg-id'] || null, text });
                break;
            }

            case 'RECONNECT':
                try { this.socket?.close(); } catch { }
                break;
        }
    }

    join(channel) {
        this.joins.push(() => {
            if (this.connected && this.wanted.has(channel)) this.raw(`JOIN #${channel}`);
        });
    }

    /** Pings Twitch every few minutes; a connection silent for too long is dropped and reopened. */
    keepAlive() {
        clearInterval(this.pingTimer);
        this.pingTimer = setInterval(() => {
            if (Date.now() - this.lastSeen > 6 * 60_000) {
                try { this.socket?.close(); } catch { }
                return;
            }
            this.raw('PING :streamorg');
        }, 4 * 60_000);
    }

    raw(line) {
        if (this.socket && this.socket.readyState === 1) this.socket.send(line + '\r\n');
    }
}
