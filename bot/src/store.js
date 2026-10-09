/**
 * Everything the bot reads from and writes to the StreamOrg database
 * (tables from 032_chat_bot.sql and 033_bot_chat_api_and_viewer_stats.sql,
 * and the overlays' chat replies from 045_overlays.sql).
 * Tokens are decrypted on the way in and encrypted on the way out, exactly
 * as the PHP app stores them.
 */
import { refreshToken } from './twitch.js';

/** Recorded broadcasts (and their viewer statistics) are kept this long. */
export const STATS_RETENTION = '12 months';

export class Store {
    /** @param {import('pg').Pool} pool @param {ReturnType<import('./crypto.js').makeCrypto>} crypto */
    constructor(pool, crypto) {
        this.pool = pool;
        this.crypto = crypto;
    }

    /**
     * Whether the database is ready for this version of the bot: the
     * migration it needs has been applied by the site (or, without one
     * named, the bot tables exist).
     *
     * @param {?string} migration e.g. "034_bot_custom_commands_and_timers"
     */
    async ready(migration = null) {
        if (!migration) {
            const { rows } = await this.pool.query("SELECT to_regclass('bot_account') IS NOT NULL AS ready");
            return rows[0].ready;
        }

        const { rows } = await this.pool.query(
            `SELECT to_regclass('schema_migrations') IS NOT NULL
                    AND EXISTS (SELECT 1 FROM schema_migrations WHERE version = $1) AS ready`,
            [migration]
        ).catch(() => ({ rows: [{ ready: false }] }));
        return rows[0].ready;
    }

    /** The bot account with its tokens readable, or null when none is connected. */
    async account() {
        const { rows } = await this.pool.query(
            `SELECT is_enabled, command_prefix, twitch_user_id, twitch_login, access_token, refresh_token, conduit_id,
                    extract(epoch FROM expires_at) * 1000 AS expires_ms
               FROM bot_account WHERE id = 1`
        );
        const row = rows[0];
        if (!row) return null;

        return {
            enabled: row.is_enabled,
            prefix: row.command_prefix,
            userId: row.twitch_user_id,
            login: row.twitch_login ? String(row.twitch_login).toLowerCase() : null,
            accessToken: this.crypto.decrypt(row.access_token),
            refreshToken: this.crypto.decrypt(row.refresh_token),
            expiresAt: row.expires_ms === null ? 0 : Number(row.expires_ms),
            conduitId: row.conduit_id,
        };
    }

    async saveConduit(conduitId) {
        await this.pool.query('UPDATE bot_account SET conduit_id = $1 WHERE id = 1', [conduitId]);
    }

    /** The Twitch app's client id and secret (Admin → API settings), or null when not configured. */
    async twitchApp() {
        const { rows } = await this.pool.query(
            "SELECT client_id, client_secret, is_enabled FROM api_settings WHERE provider = 'twitch'"
        );
        const row = rows[0];
        if (!row || !row.is_enabled) return null;

        const clientId = this.crypto.decrypt(row.client_id);
        const clientSecret = this.crypto.decrypt(row.client_secret);

        return clientId && clientSecret ? { clientId, clientSecret } : null;
    }

    async saveTokens(accessToken, refreshToken, expiresIn) {
        await this.pool.query(
            `UPDATE bot_account
                SET access_token = $1, refresh_token = $2, expires_at = now() + make_interval(secs => $3)
              WHERE id = 1`,
            [this.crypto.encrypt(accessToken), this.crypto.encrypt(refreshToken), expiresIn]
        );
    }

    /** Twitch refused the token for good: the account has to be connected again from the admin page. */
    async dropTokens() {
        await this.pool.query('UPDATE bot_account SET access_token = NULL, refresh_token = NULL, expires_at = NULL WHERE id = 1');
    }

    /** The bot's own status line for the admin page. */
    async report(state, detail, version, startedAt) {
        await this.pool.query(
            `UPDATE bot_account
                SET seen_at = now(), state = $1, state_detail = $2, version = $3, started_at = $4
              WHERE id = 1`,
            [state, detail, version, startedAt]
        );
    }

    /**
     * The channels to be in, with what the streamer's Twitch connection
     * allows and what the bot last found about its access.
     *
     * @returns {Promise<Array<{userId: number, login: string, twitchId: string, scopes: string[], access: string, checkedAt: number}>>}
     */
    async channels() {
        const { rows } = await this.pool.query(
            `SELECT b.user_id, lower(t.twitch_login) AS login, t.twitch_user_id, t.scopes, b.access,
                    coalesce(extract(epoch FROM b.access_checked_at) * 1000, 0) AS checked_ms
               FROM bot_channels b
               JOIN users u              ON u.id = b.user_id AND u.is_active
               JOIN twitch_connections t ON t.user_id = b.user_id
              WHERE b.is_enabled AND NOT b.is_blocked`
        );

        return rows.map((row) => ({
            userId: Number(row.user_id),
            login: row.login,
            twitchId: String(row.twitch_user_id),
            scopes: String(row.scopes || '').split(' ').filter(Boolean),
            access: row.access,
            checkedAt: Number(row.checked_ms),
        }));
    }

    /** What the bot found about reading a channel's chat: 'permission', 'moderator' or 'none'. */
    async setAccess(userId, access, subscriptionId, error = null) {
        await this.pool.query(
            `UPDATE bot_channels
                SET access = $2, chat_subscription_id = $3, access_checked_at = now(),
                    last_error = $4, joined_at = CASE WHEN $2 = 'none' THEN NULL ELSE coalesce(joined_at, now()) END
              WHERE user_id = $1`,
            [userId, access, subscriptionId, error]
        );
    }

    async setChattersOk(userId, ok) {
        await this.pool.query('UPDATE bot_channels SET chatters_ok = $2 WHERE user_id = $1 AND chatters_ok IS DISTINCT FROM $2', [userId, ok]);
    }

    /**
     * A streamer's own access token (for the chatter list), refreshed under
     * the same row lock the PHP app takes, so the two never spend one
     * refresh token twice. Unlike the app, the bot never deletes a
     * connection Twitch refused: it only stops using it.
     */
    async streamerToken(userId, refused = null, fetchImpl = fetch) {
        const read = async (client, lock) => (await client.query(
            `SELECT access_token, refresh_token, expires_at < now() + interval '2 minutes' AS expiring
               FROM twitch_connections WHERE user_id = $1 ${lock ? 'FOR UPDATE' : ''}`,
            [userId]
        )).rows[0];

        const row = await read(this.pool, false);
        if (!row) return null;

        const current = this.crypto.decrypt(row.access_token);
        if (!row.expiring && current !== refused) return current;

        const app = await this.twitchApp();
        if (!app) return null;

        const client = await this.pool.connect();

        try {
            await client.query('BEGIN');
            const locked = await read(client, true);

            if (!locked) {
                await client.query('ROLLBACK');
                return null;
            }

            const stored = this.crypto.decrypt(locked.access_token);

            if (!locked.expiring && stored !== refused) {
                await client.query('COMMIT');
                return stored;
            }

            const fresh = await refreshToken(app, this.crypto.decrypt(locked.refresh_token), fetchImpl);

            if (!fresh || fresh.invalid) {
                await client.query('ROLLBACK');
                return null;
            }

            await client.query(
                `UPDATE twitch_connections
                    SET access_token = $2, refresh_token = $3, expires_at = now() + make_interval(secs => $4)
                  WHERE user_id = $1`,
                [userId, this.crypto.encrypt(fresh.accessToken), this.crypto.encrypt(fresh.refreshToken), fresh.expiresIn]
            );
            await client.query('COMMIT');

            return fresh.accessToken;
        } catch (e) {
            await client.query('ROLLBACK').catch(() => {});
            throw e;
        } finally {
            client.release();
        }
    }

    /** The StreamOrg content marked live for a user right now, if any. */
    async liveContent(userId) {
        const { rows } = await this.pool.query(
            `SELECT id FROM streams WHERE user_id = $1 AND status = 'live'
           ORDER BY actual_start DESC NULLS LAST, id DESC LIMIT 1`,
            [userId]
        );

        return rows[0] ? Number(rows[0].id) : null;
    }

    /**
     * The broadcast a live stream belongs to, created on first sight, with
     * this sample's viewer count added in.
     *
     * @param {{id: string, started_at: string, title: string, viewer_count: number}} live Helix stream
     */
    async openBroadcast(userId, live) {
        const { rows } = await this.pool.query(
            `INSERT INTO twitch_broadcasts (user_id, twitch_stream_id, started_at, title, peak_viewers, viewer_samples, viewer_total)
             VALUES ($1, $2, $3, $4, $5::int, 1, $5::int)
             ON CONFLICT (user_id, twitch_stream_id) DO UPDATE
                SET last_seen_at = now(), ended_at = NULL, title = EXCLUDED.title,
                    peak_viewers = greatest(twitch_broadcasts.peak_viewers, EXCLUDED.peak_viewers),
                    viewer_samples = twitch_broadcasts.viewer_samples + 1,
                    viewer_total = twitch_broadcasts.viewer_total + EXCLUDED.viewer_total
             RETURNING id`,
            [userId, live.id, live.started_at, live.title || null, Number(live.viewer_count) || 0]
        );

        return Number(rows[0].id);
    }

    /** Ends every open broadcast except the ones still live. */
    async closeBroadcasts(liveIds) {
        await this.pool.query(
            'UPDATE twitch_broadcasts SET ended_at = last_seen_at WHERE ended_at IS NULL AND NOT (id = ANY($1::bigint[]))',
            [liveIds]
        );
    }

    /** Keeps viewers' current login and display name, writing only when they changed. */
    async rememberViewers(client, viewers) {
        if (!viewers.length) return;

        await client.query(
            `INSERT INTO chat_viewers (twitch_user_id, login, display_name)
             SELECT * FROM unnest($1::text[], $2::text[], $3::text[])
             ON CONFLICT (twitch_user_id) DO UPDATE
                SET login = EXCLUDED.login, display_name = EXCLUDED.display_name, updated_at = now()
              WHERE chat_viewers.login IS DISTINCT FROM EXCLUDED.login
                 OR chat_viewers.display_name IS DISTINCT FROM EXCLUDED.display_name`,
            [viewers.map((v) => v.id), viewers.map((v) => v.login), viewers.map((v) => v.name || null)]
        );
    }

    /**
     * Adds watch time and messages: one statement per batch, whatever its size.
     *
     * @param {{broadcastId: number, userId: number, contentId: ?number, categoryId: string, categoryName: ?string}} at
     * @param {Array<{viewer: {id: string, login: string, name: ?string}, seconds: number, messages: number}>} rows
     */
    async addViewerStats(at, rows) {
        if (!rows.length) return;

        const unique = new Map(rows.map((r) => [r.viewer.id, r.viewer]));
        const client = await this.pool.connect();

        try {
            await client.query('BEGIN');
            await this.rememberViewers(client, [...unique.values()]);
            await client.query(
                `INSERT INTO chat_viewer_stats
                     (broadcast_id, user_id, stream_id, category_id, category_name, viewer_id, watch_seconds, messages)
                 SELECT $1, $2, $3, $4, $5, v.id, v.seconds, v.messages
                   FROM unnest($6::text[], $7::int[], $8::int[]) AS v(id, seconds, messages)
                 ON CONFLICT (broadcast_id, viewer_id, stream_id, category_id) DO UPDATE
                    SET watch_seconds = chat_viewer_stats.watch_seconds + EXCLUDED.watch_seconds,
                        messages = chat_viewer_stats.messages + EXCLUDED.messages,
                        category_name = EXCLUDED.category_name,
                        last_seen_at = now()`,
                [
                    at.broadcastId, at.userId, at.contentId, at.categoryId || '', at.categoryName || null,
                    rows.map((r) => r.viewer.id), rows.map((r) => Math.round(r.seconds || 0)), rows.map((r) => r.messages || 0),
                ]
            );
            await client.query('COMMIT');
        } catch (e) {
            await client.query('ROLLBACK').catch(() => {});
            throw e;
        } finally {
            client.release();
        }
    }

    /**
     * The overlays whose settings carry chat replies (custom alert,
     * shoutout, watch streak), switched on, oldest first, with the administrators'
     * overlay switches.
     */
    async overlayReplies() {
        const settings = await this.pool.query(
            "SELECT key, value FROM app_settings WHERE key IN ('overlay.enabled', 'overlay.types_off')"
        );
        const value = (key, fallback) => settings.rows.find((r) => r.key === key)?.value ?? fallback;
        const { rows } = await this.pool.query(
            `SELECT user_id, type, settings FROM overlays
              WHERE is_enabled AND type IN ('alert', 'shoutout', 'watch_streak')
              ORDER BY created_at, id`
        );

        return {
            enabled: value('overlay.enabled', '1') === '1',
            typesOff: String(value('overlay.types_off', '')).split(',').map((t) => t.trim()).filter(Boolean),
            rows: rows.map((row) => ({ userId: Number(row.user_id), type: row.type, settings: row.settings || {} })),
        };
    }

    /** Every command row: the defaults (userId null) and the streamers' own versions. */
    async commands() {
        const { rows } = await this.pool.query(
            'SELECT user_id, code, trigger, response, is_enabled, permission, cooldown_seconds FROM bot_commands'
        );

        return rows.map((row) => ({
            userId: row.user_id === null ? null : Number(row.user_id),
            code: row.code,
            trigger: row.trigger,
            response: row.response,
            enabled: row.is_enabled,
            permission: row.permission,
            cooldown: row.cooldown_seconds,
        }));
    }

    /** Every streamer's own commands. */
    async customCommands() {
        const { rows } = await this.pool.query(
            'SELECT user_id, trigger, response, is_enabled, permission, cooldown_seconds FROM bot_custom_commands WHERE is_enabled'
        );

        return rows.map((row) => ({
            userId: Number(row.user_id),
            code: 'custom',
            trigger: row.trigger,
            response: row.response,
            enabled: row.is_enabled,
            permission: row.permission,
            cooldown: row.cooldown_seconds,
        }));
    }

    /** Every enabled timed message, with when it was last posted. */
    async timers() {
        const { rows } = await this.pool.query(
            `SELECT id, user_id, message, interval_minutes, min_messages,
                    coalesce(extract(epoch FROM last_sent_at) * 1000, 0) AS last_ms
               FROM bot_timers WHERE is_enabled`
        );

        return rows.map((row) => ({
            id: Number(row.id),
            userId: Number(row.user_id),
            message: row.message,
            intervalMs: row.interval_minutes * 60_000,
            minMessages: row.min_messages,
            lastSentAt: Number(row.last_ms),
        }));
    }

    async timerSent(id) {
        await this.pool.query('UPDATE bot_timers SET last_sent_at = now() WHERE id = $1', [id]);
    }

    async joined(userId) {
        await this.pool.query('UPDATE bot_channels SET joined_at = now(), last_error = NULL WHERE user_id = $1', [userId]);
    }

    async channelError(userId, message) {
        await this.pool.query('UPDATE bot_channels SET last_error = $2 WHERE user_id = $1', [userId, String(message).slice(0, 300)]);
    }

    async log(userId, level, message) {
        await this.pool.query(
            'INSERT INTO bot_log (user_id, level, message) VALUES ($1, $2, $3)',
            [userId, level, String(message).slice(0, 500)]
        );
    }

    /** Activity is kept for two weeks, viewer statistics for STATS_RETENTION. */
    async prune() {
        await this.pool.query("DELETE FROM bot_log WHERE at < now() - interval '14 days'");
        await this.pool.query(`DELETE FROM twitch_broadcasts WHERE started_at < now() - interval '${STATS_RETENTION}'`);
        await this.pool.query(
            `DELETE FROM chat_viewers v
              WHERE v.updated_at < now() - interval '1 day'
                AND NOT EXISTS (SELECT 1 FROM chat_viewer_stats s WHERE s.viewer_id = v.twitch_user_id)`
        );
    }
}
