/**
 * Timed messages: what a streamer has the bot post on its own while they
 * are live. A timer is due once its interval has passed since it last
 * posted (or since the stream started, if later) and at least its
 * min_messages viewer messages were sent in chat since then, so it never
 * talks into an empty chat or right as the stream starts.
 */
export class Timers {
    constructor() {
        this.byUser = new Map();
        this.counts = new Map();
        this.liveSince = new Map();
    }

    /** @param {Array<{id: number, userId: number, message: string, intervalMs: number, minMessages: number, lastSentAt: number}>} rows */
    load(rows) {
        const byUser = new Map();

        for (const timer of rows) {
            if (!byUser.has(timer.userId)) byUser.set(timer.userId, []);
            const known = this.find(timer.id);
            byUser.get(timer.userId).push({ ...timer, lastSentAt: Math.max(timer.lastSentAt, known?.lastSentAt || 0) });
        }

        this.byUser = byUser;

        for (const id of [...this.counts.keys()]) {
            if (!this.find(id)) this.counts.delete(id);
        }
    }

    find(id) {
        for (const list of this.byUser.values()) {
            const timer = list.find((t) => t.id === id);
            if (timer) return timer;
        }
        return null;
    }

    /** A viewer's chat message counts towards every timer of that channel. */
    countMessage(userId) {
        for (const timer of this.byUser.get(userId) || []) {
            this.counts.set(timer.id, (this.counts.get(timer.id) || 0) + 1);
        }
    }

    /**
     * The timers to post now, given which channels are live.
     *
     * @param {(userId: number) => boolean} isLive
     * @returns {Array<object>} each marked as sent
     */
    due(isLive, now = Date.now()) {
        const due = [];

        for (const [userId, timers] of this.byUser) {
            if (!isLive(userId)) {
                this.liveSince.delete(userId);
                continue;
            }

            if (!this.liveSince.has(userId)) this.liveSince.set(userId, now);
            const since = this.liveSince.get(userId);

            for (const timer of timers) {
                const from = Math.max(timer.lastSentAt, since);

                if (now - from >= timer.intervalMs && (this.counts.get(timer.id) || 0) >= timer.minMessages) {
                    timer.lastSentAt = now;
                    this.counts.set(timer.id, 0);
                    due.push(timer);
                }
            }
        }

        return due;
    }
}
