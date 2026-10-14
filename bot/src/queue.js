/**
 * What the bot says goes through a queue per channel, so it never runs
 * into Twitch's chat limits:
 *
 * - messages leave one at a time, at least gapMs apart, and at most
 *   `limit` within any `windowMs` (Twitch allows 20 per 30 seconds);
 * - a message with a key (a command in a channel, a timer, an alert) is
 *   dropped while another with the same key is still waiting, so a burst
 *   of the same command answers once;
 * - at most maxPending messages wait per channel; more are dropped;
 * - a message that waited longer than its ttlMs is dropped, as the chat
 *   has moved on.
 *
 * One message may have several parts (a long schedule): they go out in
 * order, each paced like any message.
 */
export class ChatQueue {
    /**
     * @param {{send: (channel: object, text: string, replyTo: ?string) => Promise<boolean>,
     *          onDrop?: (channel: object, reason: string) => void, gapMs?: number, limit?: number,
     *          windowMs?: number, maxPending?: number, now?: () => number, sleep?: (ms: number) => Promise<void>}} deps
     */
    constructor({ send, onDrop = () => {}, gapMs = 1100, limit = 20, windowMs = 30_000, maxPending = 6, now = Date.now, sleep = null }) {
        this.send = send;
        this.onDrop = onDrop;
        this.gapMs = gapMs;
        this.limit = limit;
        this.windowMs = windowMs;
        this.maxPending = maxPending;
        this.now = now;
        this.sleep = sleep || ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
        this.channels = new Map();
    }

    /**
     * @param {{twitchId: string}} channel
     * @param {{texts: string[], key?: ?string, replyTo?: ?string, ttlMs?: number}} message
     * @returns {Promise<boolean>} whether every part was posted
     */
    push(channel, { texts, key = null, replyTo = null, ttlMs = 60_000 }) {
        const state = this.state(channel.twitchId);
        const parts = texts.filter((t) => String(t).trim() !== '');

        if (parts.length === 0) return Promise.resolve(false);

        if (key !== null && state.items.some((item) => item.key === key)) {
            this.onDrop(channel, 'duplicate');
            return Promise.resolve(false);
        }

        if (state.items.length >= this.maxPending) {
            this.onDrop(channel, 'full');
            return Promise.resolve(false);
        }

        return new Promise((resolve) => {
            state.items.push({ channel, texts: parts, key, replyTo, ttlMs, at: this.now(), resolve });
            if (!state.running) this.run(state);
        });
    }

    /** How many messages wait in a channel (the one being sent included). */
    pending(twitchId) {
        return this.channels.get(twitchId)?.items.length || 0;
    }

    state(twitchId) {
        if (!this.channels.has(twitchId)) this.channels.set(twitchId, { items: [], running: false, sent: [], last: null });
        return this.channels.get(twitchId);
    }

    /** How long to wait before the next message may leave this channel. */
    wait(state) {
        const now = this.now();
        state.sent = state.sent.filter((at) => now - at < this.windowMs);

        const gap = state.last === null ? 0 : this.gapMs - (now - state.last);
        const window = state.sent.length >= this.limit ? state.sent[0] + this.windowMs - now : 0;

        return Math.max(0, gap, window);
    }

    async run(state) {
        state.running = true;

        try {
            while (state.items.length) {
                const item = state.items[0];

                if (this.now() - item.at > item.ttlMs) {
                    state.items.shift();
                    this.onDrop(item.channel, 'stale');
                    item.resolve(false);
                    continue;
                }

                let ok = true;

                for (let i = 0; i < item.texts.length && ok; i++) {
                    const wait = this.wait(state);
                    if (wait > 0) await this.sleep(wait);

                    state.last = this.now();
                    state.sent.push(state.last);
                    ok = await this.send(item.channel, item.texts[i], i === 0 ? item.replyTo : null).catch(() => false);
                }

                state.items.shift();
                item.resolve(ok);
            }
        } finally {
            state.running = false;
        }
    }
}
