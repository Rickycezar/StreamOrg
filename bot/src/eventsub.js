/**
 * Twitch EventSub over WebSocket (wss://eventsub.wss.twitch.tv/ws), used
 * as the shard of the bot's conduit: Twitch delivers the conduit's events
 * (chat messages) to whichever socket the shard points at.
 *
 * Emits:
 *   welcome      (sessionId)  a session is ready; point the shard at it
 *   notification ({type, event, subscription})
 *   revocation   (subscription)
 *   closed       ()            lost; it reconnects by itself
 *
 * Follows Twitch's rules: a reconnect message opens the new URL and keeps
 * the old socket until the new one says welcome; silence longer than the
 * keepalive timeout means the connection is dead; a notification whose
 * message id was seen before is dropped.
 */
import { EventEmitter } from 'node:events';

const URL_DEFAULT = 'wss://eventsub.wss.twitch.tv/ws';
const SEEN_MAX = 1000;

export class EventSubSocket extends EventEmitter {
    /** @param {{url?: string, WebSocketImpl?: typeof WebSocket}} options */
    constructor({ url = URL_DEFAULT, WebSocketImpl = globalThis.WebSocket } = {}) {
        super();
        this.url = url;
        this.WebSocketImpl = WebSocketImpl;
        this.socket = null;
        this.pending = null;
        this.sessionId = null;
        this.stopped = true;
        this.attempt = 0;
        this.timer = null;
        this.watchdog = null;
        this.keepaliveMs = 30_000;
        this.seen = new Set();
    }

    get connected() {
        return this.sessionId !== null && this.socket !== null && this.socket.readyState === 1;
    }

    start() {
        if (!this.stopped) return;
        this.stopped = false;
        this.open(this.url);
    }

    stop() {
        this.stopped = true;
        clearTimeout(this.timer);
        clearTimeout(this.watchdog);

        for (const socket of [this.socket, this.pending]) {
            if (socket) {
                try { socket.close(); } catch { }
            }
        }

        this.socket = null;
        this.pending = null;
        this.sessionId = null;
    }

    /** Opens a socket: the current one, or (on Twitch's reconnect message) one pending until it says welcome. */
    open(url, pending = false) {
        const socket = new this.WebSocketImpl(url);

        if (pending) {
            this.pending = socket;
        } else {
            this.socket = socket;
        }

        socket.addEventListener('message', (event) => this.handle(socket, String(event.data)));

        socket.addEventListener('close', () => {
            if (socket === this.pending) {
                this.pending = null;
                return;
            }

            if (socket !== this.socket) return;

            this.socket = null;
            this.sessionId = null;
            clearTimeout(this.watchdog);
            this.emit('closed');
            this.retry();
        });

        socket.addEventListener('error', () => {
            try { socket.close(); } catch { }
        });
    }

    retry() {
        if (this.stopped) return;

        const delay = Math.min(60_000, 1000 * 2 ** Math.min(this.attempt, 6));
        this.attempt++;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.open(this.url), delay);
    }

    handle(socket, raw) {
        if (socket !== this.socket && socket !== this.pending) return;

        let message;

        try { message = JSON.parse(raw); } catch { return; }

        const type = message?.metadata?.message_type;
        const id = message?.metadata?.message_id;

        if (id && (type === 'notification' || type === 'revocation')) {
            if (this.seen.has(id)) return;
            this.seen.add(id);
            if (this.seen.size > SEEN_MAX) this.seen.delete(this.seen.values().next().value);
        }

        switch (type) {
            case 'session_welcome': {
                const session = message.payload.session;
                this.keepaliveMs = (Number(session.keepalive_timeout_seconds) || 30) * 1000;

                if (socket === this.pending) {
                    const old = this.socket;
                    this.socket = socket;
                    this.pending = null;
                    try { old?.close(); } catch { }
                }

                this.sessionId = session.id;
                this.attempt = 0;
                this.alive();
                this.emit('welcome', session.id);
                break;
            }

            case 'session_keepalive':
                if (socket === this.socket) this.alive();
                break;

            case 'session_reconnect':
                if (socket === this.socket) {
                    this.alive();
                    this.open(message.payload.session.reconnect_url, true);
                }
                break;

            case 'notification':
                if (socket === this.socket) this.alive();
                this.emit('notification', {
                    type: message.payload.subscription.type,
                    subscription: message.payload.subscription,
                    event: message.payload.event,
                });
                break;

            case 'revocation':
                this.emit('revocation', message.payload.subscription);
                break;
        }
    }

    /** Resets the silence timer: Twitch sends something (at least a keepalive) within keepaliveMs. */
    alive() {
        clearTimeout(this.watchdog);
        this.watchdog = setTimeout(() => {
            try { this.socket?.close(); } catch { }
        }, this.keepaliveMs + 5000);
    }
}
