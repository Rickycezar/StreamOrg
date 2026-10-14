/**
 * The daily look at a channel's follower list, to tell who stopped
 * following (Twitch announces follows, never unfollows).
 *
 * Only channels an administrator switched it on for, while the
 * administrators' unfollows setting is on (Store.followersDue). The list
 * is read with a token allowed to (moderator:read:followers): the
 * streamer's own, or the bot's when it is a moderator there. Channels
 * larger than PAGES_MAX pages are left alone, as reading them every day
 * would cost too much.
 */
export const PAGE_SIZE = 100;
export const PAGES_MAX = 300;
export const FOLLOWERS_SCOPE = 'moderator:read:followers';

export class FollowerCheck {
    /**
     * @param {{store: object, helix: object, botToken: () => Promise<?string>, botId: () => ?string, botScopes: () => string[],
     *          fetchImpl?: typeof fetch}} deps
     */
    constructor({ store, helix, botToken, botId, botScopes, fetchImpl = fetch }) {
        this.store = store;
        this.helix = helix;
        this.botToken = botToken;
        this.botId = botId;
        this.botScopes = botScopes;
        this.fetch = fetchImpl;
        this.running = false;
    }

    /** Looks at every channel that is due, one after another. */
    async tick(channels) {
        if (this.running) return;
        this.running = true;

        try {
            const byUser = new Map(channels.map((c) => [c.userId, c]));

            for (const userId of await this.store.followersDue()) {
                const channel = byUser.get(userId);

                if (!channel) {
                    await this.store.followersFailed(userId, 'The bot is not in this channel');
                    continue;
                }

                await this.check(channel).catch(async (e) => {
                    await this.store.followersFailed(userId, e.message).catch(() => {});
                });
            }
        } finally {
            this.running = false;
        }
    }

    async check(channel) {
        const list = await this.followers(channel);

        if (typeof list === 'string') {
            await this.store.followersFailed(channel.userId, list);
            await this.store.log(channel.userId, 'warn', `Could not read the follower list: ${list}`);
            return;
        }

        const result = await this.store.applyFollowers(channel.userId, list.followers, list.total);
        await this.store.log(channel.userId, 'info', result.first
            ? `Follower list kept (${list.total}); unfollows are noticed from tomorrow`
            : `Follower list checked: ${result.gone} unfollow(s), ${result.added} new follow(s)`);
    }

    /**
     * Every follower, through whichever token may read them.
     *
     * @returns {Promise<{followers: Array<{id: string, login: string, name: string, followedAt: string}>, total: number}|string>}
     *          the list, or why it could not be read
     */
    async followers(channel) {
        const attempts = [];

        if (channel.scopes.includes(FOLLOWERS_SCOPE)) {
            attempts.push((refused) => this.store.streamerToken(channel.userId, refused, this.fetch));
        }

        if (channel.access === 'moderator' && this.botId() && this.botScopes().includes(FOLLOWERS_SCOPE)) {
            attempts.push(() => this.botToken());
        }

        if (attempts.length === 0) return 'no permission: the streamer should connect Twitch again, or make the bot a moderator';

        let reason = 'Twitch refused';

        for (const attempt of attempts) {
            let token = await attempt(null).catch(() => null);
            if (!token) continue;

            let list = await this.pages(channel.twitchId, token);

            if (list === 401) {
                token = await attempt(token).catch(() => null);
                list = token ? await this.pages(channel.twitchId, token) : 'Twitch refused the token';
            }

            if (typeof list === 'object' && list !== null && list !== 401) return list;
            if (typeof list === 'string') reason = list;
        }

        return reason;
    }

    /** @returns {Promise<{followers: Array, total: number}|401|string>} */
    async pages(broadcasterId, token) {
        const followers = [];
        let after;
        let total = 0;

        for (let page = 0; page < PAGES_MAX; page++) {
            const result = await this.helix.user(token, 'GET', 'channels/followers', {
                query: { broadcaster_id: broadcasterId, first: PAGE_SIZE, after },
            });

            if (result.status === 401) return 401;
            if (result.status !== 200) return `Twitch answered ${result.status}`;

            total = Number(result.data.total) || 0;
            if (total > PAGE_SIZE * PAGES_MAX) return `too many followers to check every day (${total})`;

            for (const f of result.data.data || []) {
                followers.push({ id: String(f.user_id), login: f.user_login, name: f.user_name, followedAt: f.followed_at });
            }

            after = result.data.pagination?.cursor;
            if (!after) break;
        }

        return { followers, total };
    }
}
