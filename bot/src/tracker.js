/**
 * Simple viewer statistics while a channel is live.
 *
 * Every minute it asks Twitch which of the bot's channels are live (one
 * call per 100 channels, app token). For each live one it keeps a
 * broadcast row, notes the StreamOrg content marked live (if any) and the
 * stream's category, and reads the chatter list: everyone in it gets the
 * time since the last sample added to their watch time. Chat messages are
 * counted in memory and written with the same minute's batch, so the
 * database sees a few statements per channel per minute, however busy the
 * chat.
 *
 * The chatter list needs moderator:read:chatters from a moderator of the
 * channel: the streamer's own connection when it granted it, or the bot's
 * when the streamer made it a moderator. Without either, only messages
 * are counted.
 */
const CHATTERS_PAGE = 1000;
const CHATTERS_PAGES_MAX = 10;

export class Tracker {
    /**
     * @param {{store: object, helix: import('./helix.js').Helix, botToken: () => Promise<?string>, botId: () => ?string,
     *          intervalMs?: number, now?: () => number, fetchImpl?: typeof fetch}} deps
     */
    constructor({ store, helix, botToken, botId, intervalMs = 60_000, now = Date.now, fetchImpl = fetch }) {
        this.store = store;
        this.helix = helix;
        this.botToken = botToken;
        this.botId = botId;
        this.intervalMs = intervalMs;
        this.now = now;
        this.fetch = fetchImpl;
        this.channels = new Map();
        this.live = new Map();
        this.messages = new Map();
        this.running = false;
    }

    /** @param {Array<{userId: number, twitchId: string, login: string, scopes: string[], access: string}>} channels */
    setChannels(channels) {
        this.channels = new Map(channels.map((c) => [c.twitchId, c]));

        for (const twitchId of [...this.live.keys()]) {
            if (!this.channels.has(twitchId)) this.live.delete(twitchId);
        }
    }

    /** Whether a channel is being recorded right now (live, as of the last check). */
    isLive(twitchId) {
        return this.live.has(twitchId);
    }

    /** Counts a chat message towards the current minute, if the channel is live. */
    countMessage(channelTwitchId, viewer) {
        if (!this.live.has(channelTwitchId) || !viewer?.id) return;

        const perChannel = this.messages.get(channelTwitchId) || new Map();
        const entry = perChannel.get(viewer.id) || { viewer, messages: 0 };
        entry.viewer = viewer;
        entry.messages++;
        perChannel.set(viewer.id, entry);
        this.messages.set(channelTwitchId, perChannel);
    }

    /** One minute's work; overlapping calls are skipped. */
    async tick() {
        if (this.running) return;
        this.running = true;

        try {
            const streams = await this.liveStreams();
            const openIds = [];

            for (const [twitchId, channel] of this.channels) {
                const stream = streams.get(twitchId);

                if (!stream) {
                    this.live.delete(twitchId);
                    this.messages.delete(twitchId);
                    continue;
                }

                const id = await this.record(channel, stream).catch((e) => {
                    console.error(`tracker #${channel.login}:`, e.message);
                    return this.live.get(twitchId)?.at.broadcastId ?? null;
                });

                if (id !== null) openIds.push(id);
            }

            await this.store.closeBroadcasts(openIds);
        } finally {
            this.running = false;
        }
    }

    /** @returns {Promise<Map<string, object>>} Helix streams of the live channels, by broadcaster id */
    async liveStreams() {
        const ids = [...this.channels.keys()];
        const live = new Map();

        for (let i = 0; i < ids.length; i += 100) {
            const result = await this.helix.app('GET', 'streams', { query: { user_id: ids.slice(i, i + 100), first: 100 } });
            if (result.status !== 200) throw new Error(`streams: ${result.status}`);

            for (const stream of result.data.data || []) {
                if (stream.type === 'live') live.set(String(stream.user_id), stream);
            }
        }

        return live;
    }

    /** @returns {Promise<number>} the broadcast id */
    async record(channel, stream) {
        const previous = this.live.get(channel.twitchId);
        const broadcastId = await this.store.openBroadcast(channel.userId, stream);
        const contentId = await this.store.liveContent(channel.userId);
        const at = {
            broadcastId,
            userId: channel.userId,
            contentId,
            categoryId: String(stream.game_id || ''),
            categoryName: stream.game_name || null,
        };

        const now = this.now();
        const sameBroadcast = previous && previous.at.broadcastId === broadcastId;
        const seconds = sameBroadcast ? Math.min(now - previous.sampledAt, 2 * this.intervalMs) / 1000 : this.intervalMs / 1000;

        this.live.set(channel.twitchId, { at, sampledAt: now });

        const counted = this.messages.get(channel.twitchId) || new Map();
        this.messages.delete(channel.twitchId);

        const chatters = await this.chatters(channel);
        const rows = new Map();

        for (const viewer of chatters || []) {
            if (viewer.id === this.botId()) continue;
            rows.set(viewer.id, { viewer, seconds, messages: 0 });
        }

        for (const [id, entry] of counted) {
            const row = rows.get(id) || { viewer: entry.viewer, seconds: 0, messages: 0 };
            row.messages += entry.messages;
            rows.set(id, row);
        }

        const target = sameBroadcast ? previous.at : at;
        await this.store.addViewerStats(target, [...rows.values()]);

        await this.store.setChattersOk(channel.userId, chatters !== null);

        return broadcastId;
    }

    /**
     * Everyone in chat now, through whichever moderator token is allowed:
     * the streamer's own, else the bot's when it is a moderator there.
     *
     * @returns {Promise<Array<{id: string, login: string, name: string}>|null>} null when no token may read the list
     */
    async chatters(channel) {
        const attempts = [];

        if (channel.scopes.includes('moderator:read:chatters')) {
            attempts.push({ moderatorId: channel.twitchId, token: (refused) => this.store.streamerToken(channel.userId, refused, this.fetch) });
        }

        if (channel.access === 'moderator' && this.botId()) {
            attempts.push({ moderatorId: this.botId(), token: () => this.botToken() });
        }

        for (const attempt of attempts) {
            let token = await attempt.token(null).catch(() => null);
            if (!token) continue;

            let list = await this.chatterPages(channel.twitchId, attempt.moderatorId, token);

            if (list === 401) {
                token = await attempt.token(token).catch(() => null);
                list = token ? await this.chatterPages(channel.twitchId, attempt.moderatorId, token) : null;
            }

            if (Array.isArray(list)) return list;
        }

        return null;
    }

    /** @returns {Promise<Array|401|null>} */
    async chatterPages(broadcasterId, moderatorId, token) {
        const list = [];
        let after;

        for (let page = 0; page < CHATTERS_PAGES_MAX; page++) {
            const result = await this.helix.user(token, 'GET', 'chat/chatters', {
                query: { broadcaster_id: broadcasterId, moderator_id: moderatorId, first: CHATTERS_PAGE, after },
            });

            if (result.status === 401) return 401;
            if (result.status !== 200) return null;

            for (const c of result.data.data || []) list.push({ id: String(c.user_id), login: c.user_login, name: c.user_name });

            after = result.data.pagination?.cursor;
            if (!after) break;
        }

        return list;
    }
}
