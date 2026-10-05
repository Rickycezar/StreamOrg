/**
 * Everything the bot reads from and writes to the StreamOrg database
 * (tables from 032_chat_bot.sql). Tokens are decrypted on the way in and
 * encrypted on the way out, exactly as the PHP app stores them.
 */
export class Store {
    /** @param {import('pg').Pool} pool @param {ReturnType<import('./crypto.js').makeCrypto>} crypto */
    constructor(pool, crypto) {
        this.pool = pool;
        this.crypto = crypto;
    }

    /** Whether the app's migrations have created the bot tables yet. */
    async ready() {
        const { rows } = await this.pool.query("SELECT to_regclass('bot_account') IS NOT NULL AS ready");
        return rows[0].ready;
    }

    /** The bot account with its tokens readable, or null when none is connected. */
    async account() {
        const { rows } = await this.pool.query(
            `SELECT is_enabled, command_prefix, twitch_user_id, twitch_login, access_token, refresh_token,
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
        };
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

    /** @returns {Promise<Array<{userId: number, login: string}>>} the channels to be in */
    async channels() {
        const { rows } = await this.pool.query(
            `SELECT b.user_id, lower(t.twitch_login) AS login
               FROM bot_channels b
               JOIN users u              ON u.id = b.user_id AND u.is_active
               JOIN twitch_connections t ON t.user_id = b.user_id
              WHERE b.is_enabled AND NOT b.is_blocked`
        );

        return rows.map((row) => ({ userId: Number(row.user_id), login: row.login }));
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

    /** Activity is kept for two weeks. */
    async prune() {
        await this.pool.query("DELETE FROM bot_log WHERE at < now() - interval '14 days'");
    }
}
