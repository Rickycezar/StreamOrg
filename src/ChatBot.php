<?php
declare(strict_types=1);

/**
 * The app's side of the chat bot (the Node service in bot/; see
 * 032_chat_bot.sql): the Twitch account it speaks as, which channels it
 * joins, the commands it answers and what it has been doing.
 *
 * The bot reads all of this from the database and reports its status
 * there; this class never talks to the bot directly. Every change ends
 * with NOTIFY streamorg_bot so the bot picks it up at once.
 *
 * Commands are built-in behaviours (CODES), each with a default row an
 * admin edits and, per streamer, an optional personal row that replaces
 * it in their channel: trigger, reply, who may use it, cooldown, on/off.
 */
final class ChatBot
{
    /**
     * Scopes asked of the bot account: reading and writing chat through
     * Twitch's chat API (so it shows as a chat bot), and the chatter list
     * in channels where the streamer made it a moderator.
     */
    public const SCOPES = 'user:read:chat user:write:chat user:bot moderator:read:chatters';

    /** Built-in behaviours, and the {placeholders} their reply may use. */
    public const CODES = [
        'heartbeat' => ['user', 'channel', 'uptime'],
    ];

    public const PERMISSIONS = ['everyone', 'subscriber', 'vip', 'moderator', 'broadcaster'];

    public const RESPONSE_MAX = 450;
    public const COOLDOWN_MAX = 3600;

    /** The bot counts as online when it reported within this many seconds. */
    public const ONLINE_WITHIN = 90;

    private const STATE_KEY = 'streamorg_bot_state';

    /** @return array<string,mixed> the account row, without tokens, plus whether the bot is online */
    public static function account(): array
    {
        $row = Database::connection()->query(
            "SELECT is_enabled, command_prefix, twitch_user_id, twitch_login, scopes, connected_at,
                    seen_at, started_at, state, state_detail, version,
                    coalesce(seen_at > now() - make_interval(secs => " . self::ONLINE_WITHIN . "), false) AS online
               FROM bot_account WHERE id = 1"
        )->fetch();

        return $row === false ? ['is_enabled' => false, 'command_prefix' => '!', 'twitch_login' => null, 'online' => false] : $row;
    }

    /** Whether streamers can add the bot: an account is connected and an admin switched it on. */
    public static function isAvailable(): bool
    {
        $account = self::account();

        return !empty($account['is_enabled']) && !empty($account['twitch_login']);
    }

    /** The Twitch consent page for the bot account, to be opened while signed in to Twitch as the bot. */
    public static function authorizeUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION[self::STATE_KEY] = $state;

        return 'https://id.twitch.tv/oauth2/authorize?' . http_build_query([
            'client_id'     => Twitch::clientId(),
            'redirect_uri'  => TwitchUser::redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'state'         => $state,
            'force_verify'  => 'true',
        ]);
    }

    /** Whether a Twitch callback is the bot account being connected. */
    public static function isBotCallback(string $state): bool
    {
        $expected = $_SESSION[self::STATE_KEY] ?? '';

        return is_string($expected) && $expected !== '' && hash_equals($expected, $state);
    }

    /**
     * Finishes connecting the bot account: stores its tokens, encrypted.
     *
     * @return string the bot's Twitch login
     * @throws UserError when Twitch does not hand over a usable token
     */
    public static function complete(string $code): string
    {
        unset($_SESSION[self::STATE_KEY]);

        $tokens = $code === '' ? null : TwitchUser::tokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => TwitchUser::redirectUri(),
        ]);

        $who = $tokens === null ? null : Http::json(Http::get('https://id.twitch.tv/oauth2/validate', [
            'Authorization' => 'OAuth ' . $tokens['access_token'],
        ]));

        if ($who === null || empty($who['user_id']) || empty($who['login'])) {
            error_log('StreamOrg bot connect: ' . TwitchUser::lastError());
            throw new UserError(__('ui.message.bot_connect_failed'));
        }

        Database::connection()->prepare(
            'UPDATE bot_account
                SET twitch_user_id = ?, twitch_login = ?, access_token = ?, refresh_token = ?,
                    expires_at = now() + make_interval(secs => ?), scopes = ?, connected_at = now()
              WHERE id = 1'
        )->execute([
            (string) $who['user_id'],
            (string) $who['login'],
            Crypto::encrypt($tokens['access_token']),
            Crypto::encrypt($tokens['refresh_token']),
            $tokens['expires_in'],
            implode(' ', $tokens['scope']),
        ]);

        self::log(null, 'info', 'Bot account connected: ' . $who['login']);
        self::notify();

        return (string) $who['login'];
    }

    /** Forgets the bot account (revoking its token at Twitch, best effort) and switches the bot off. */
    public static function disconnect(): void
    {
        $token = Crypto::decrypt(Database::connection()->query('SELECT access_token FROM bot_account WHERE id = 1')->fetchColumn() ?: null);

        if ($token !== null && $token !== '') {
            Http::post('https://id.twitch.tv/oauth2/revoke', http_build_query([
                'client_id' => Twitch::clientId(),
                'token'     => $token,
            ]), ['Content-Type' => 'application/x-www-form-urlencoded']);
        }

        Database::connection()->exec(
            'UPDATE bot_account
                SET is_enabled = false, twitch_user_id = NULL, twitch_login = NULL, access_token = NULL,
                    refresh_token = NULL, expires_at = NULL, scopes = NULL, connected_at = NULL
              WHERE id = 1'
        );

        self::log(null, 'info', 'Bot account disconnected');
        self::notify();
    }

    /** @throws UserError on a prefix outside what chat commands use */
    public static function saveSettings(bool $enabled, string $prefix): void
    {
        $prefix = trim($prefix);

        if (!preg_match('/^[!?.#$%&*+~-]{1,2}$/', $prefix)) {
            throw new UserError(__('ui.message.bot_prefix_invalid'));
        }

        Database::connection()->prepare('UPDATE bot_account SET is_enabled = ?, command_prefix = ? WHERE id = 1')
            ->execute([$enabled ? 'true' : 'false', $prefix]);

        self::log(null, 'info', $enabled ? 'Bot switched on' : 'Bot switched off');
        self::notify();
    }

    /**
     * @return array{is_enabled:bool, is_blocked:bool, joined_at:?string, last_error:?string, access:string, chatters_ok:?bool}|null
     *         null when never added
     */
    public static function channel(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT is_enabled, is_blocked, joined_at, last_error, access, chatters_ok FROM bot_channels WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Adds the bot to a streamer's channel, or removes it: by the streamer, or by an admin. */
    public static function setChannel(int $userId, bool $enabled, bool $byAdmin = false): void
    {
        Database::connection()->prepare(
            'INSERT INTO bot_channels (user_id, is_enabled) VALUES (?, ?)
             ON CONFLICT (user_id) DO UPDATE SET is_enabled = EXCLUDED.is_enabled, last_error = NULL'
        )->execute([$userId, $enabled ? 'true' : 'false']);

        self::log($userId, 'info', ($enabled ? 'Added to the channel' : 'Removed from the channel') . ($byAdmin ? ' by an admin' : ''));
        self::notify();
    }

    /**
     * Asks the bot to check a channel's access again now, instead of at its
     * next retry: after the streamer reconnected Twitch (new permissions)
     * or made the bot a moderator, neither of which Twitch announces.
     */
    public static function recheck(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE bot_channels SET access = 'unknown', access_checked_at = NULL WHERE user_id = ? AND is_enabled"
        );
        $stmt->execute([$userId]);

        if ($stmt->rowCount() > 0) {
            self::notify();
        }
    }

    /** An admin keeps the bot out of a channel (or lets it back in). */
    public static function setBlocked(int $userId, bool $blocked): void
    {
        Database::connection()->prepare('UPDATE bot_channels SET is_blocked = ? WHERE user_id = ?')
            ->execute([$blocked ? 'true' : 'false', $userId]);

        self::log($userId, 'warn', $blocked ? 'Blocked by an admin' : 'Unblocked by an admin');
        self::notify();
    }

    /**
     * Every channel the bot could be in, for the admin: users who added it
     * (or had it added) and active users with a connected Twitch account,
     * the ones with the bot first. 'added' tells them apart.
     *
     * @return list<array<string,mixed>>
     */
    public static function channels(): array
    {
        return Database::connection()->query(
            'SELECT u.id AS user_id, u.username, u.display_name, t.twitch_login,
                    b.user_id IS NOT NULL AS added,
                    coalesce(b.is_enabled, false) AS is_enabled, coalesce(b.is_blocked, false) AS is_blocked,
                    b.joined_at, b.last_error, b.created_at, coalesce(b.access, \'unknown\') AS access, b.chatters_ok
               FROM users u
          LEFT JOIN bot_channels b       ON b.user_id = u.id
          LEFT JOIN twitch_connections t ON t.user_id = u.id
              WHERE b.user_id IS NOT NULL OR (t.user_id IS NOT NULL AND u.is_active)
           ORDER BY coalesce(b.is_enabled, false) DESC, lower(coalesce(t.twitch_login, u.username))'
        )->fetchAll();
    }

    /**
     * The default commands, one per built-in behaviour.
     *
     * @return array<string, array<string,mixed>> by code
     */
    public static function defaults(): array
    {
        $rows = Database::connection()->query(
            'SELECT code, trigger, response, is_enabled, permission, cooldown_seconds FROM bot_commands WHERE user_id IS NULL'
        )->fetchAll();

        return array_intersect_key(array_column($rows, null, 'code'), self::CODES);
    }

    /**
     * The commands as they work in one channel: the streamer's own version
     * where they made one, the default otherwise.
     *
     * @return array<string, array<string,mixed>> by code, each with 'personal' telling which
     */
    public static function commandsFor(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT code, trigger, response, is_enabled, permission, cooldown_seconds FROM bot_commands WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $personal = array_column($stmt->fetchAll(), null, 'code');

        $commands = [];

        foreach (self::defaults() as $code => $default) {
            $commands[$code] = ($personal[$code] ?? $default) + ['personal' => isset($personal[$code]), 'default' => $default];
        }

        return $commands;
    }

    /**
     * Reads a command form.
     *
     * @return array{trigger:string, response:string, is_enabled:bool, permission:string, cooldown_seconds:int}
     * @throws UserError naming what is wrong
     */
    public static function commandFromInput(string $code, array $input): array
    {
        if (!isset(self::CODES[$code])) {
            throw new UserError(__('ui.message.not_found'));
        }

        $trigger    = strtolower(ltrim(trim((string) ($input['trigger'] ?? '')), '!?.#$%&*+~-'));
        $response   = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['response'] ?? '')));
        $permission = (string) ($input['permission'] ?? 'everyone');
        $cooldown   = filter_var($input['cooldown_seconds'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::COOLDOWN_MAX]]);

        if (!preg_match('/^[a-z0-9_]{1,25}$/', $trigger)) {
            throw new UserError(__('ui.message.bot_trigger_invalid'));
        }

        if ($response === '' || mb_strlen($response) > self::RESPONSE_MAX) {
            throw new UserError(sprintf(__('ui.message.bot_response_invalid'), self::RESPONSE_MAX));
        }

        if (!in_array($permission, self::PERMISSIONS, true) || $cooldown === false) {
            throw new UserError(__('ui.message.invalid_input'));
        }

        return [
            'trigger'          => $trigger,
            'response'         => $response,
            'is_enabled'       => !empty($input['is_enabled']),
            'permission'       => $permission,
            'cooldown_seconds' => $cooldown,
        ];
    }

    /**
     * Saves a command: the default (no user) or a streamer's own version.
     *
     * @param array{trigger:string, response:string, is_enabled:bool, permission:string, cooldown_seconds:int} $command
     * @throws UserError when another command in the channel already answers to that trigger
     */
    public static function saveCommand(?int $userId, string $code, array $command): void
    {
        $others = $userId === null ? self::defaults() : self::commandsFor($userId);

        foreach ($others as $otherCode => $other) {
            if ($otherCode !== $code && $other['trigger'] === $command['trigger']) {
                throw new UserError(sprintf(__('ui.message.bot_trigger_taken'), $command['trigger']));
            }
        }

        $pdo = Database::connection();

        $pdo->prepare(
            $userId === null
                ? 'UPDATE bot_commands
                      SET trigger = :trigger, response = :response, is_enabled = :enabled, permission = :permission,
                          cooldown_seconds = :cooldown, updated_at = now()
                    WHERE user_id IS NULL AND code = :code'
                : 'INSERT INTO bot_commands (user_id, code, trigger, response, is_enabled, permission, cooldown_seconds)
                   VALUES (:user, :code, :trigger, :response, :enabled, :permission, :cooldown)
                   ON CONFLICT (user_id, code) WHERE user_id IS NOT NULL DO UPDATE
                      SET trigger = EXCLUDED.trigger, response = EXCLUDED.response, is_enabled = EXCLUDED.is_enabled,
                          permission = EXCLUDED.permission, cooldown_seconds = EXCLUDED.cooldown_seconds, updated_at = now()'
        )->execute(($userId === null ? [] : ['user' => $userId]) + [
            'code'       => $code,
            'trigger'    => $command['trigger'],
            'response'   => $command['response'],
            'enabled'    => $command['is_enabled'] ? 'true' : 'false',
            'permission' => $command['permission'],
            'cooldown'   => $command['cooldown_seconds'],
        ]);

        self::log($userId, 'info', ($userId === null ? 'Default command saved: ' : 'Command personalised: ') . $code);
        self::notify();
    }

    /** Drops a streamer's own version of a command: their channel uses the default again. */
    public static function resetCommand(int $userId, string $code): void
    {
        Database::connection()->prepare('DELETE FROM bot_commands WHERE user_id = ? AND code = ?')->execute([$userId, $code]);

        self::log($userId, 'info', 'Command back to default: ' . $code);
        self::notify();
    }

    /**
     * What the bot has recorded: broadcasts, distinct viewers and the
     * latest broadcast, for one channel or all of them (with the space the
     * tables take, for the admin).
     *
     * @return array{broadcasts:int, viewers:int, rows:int, last:?string, live:bool, bytes:?int}
     */
    public static function statsSummary(?int $userId = null): array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT (SELECT count(*) FROM twitch_broadcasts WHERE CAST(:user AS bigint) IS NULL OR user_id = :user) AS broadcasts,
                    (SELECT count(DISTINCT viewer_id) FROM chat_viewer_stats WHERE CAST(:user AS bigint) IS NULL OR user_id = :user) AS viewers,
                    (SELECT count(*) FROM chat_viewer_stats WHERE CAST(:user AS bigint) IS NULL OR user_id = :user) AS rows,
                    (SELECT max(started_at) FROM twitch_broadcasts WHERE CAST(:user AS bigint) IS NULL OR user_id = :user) AS last,
                    EXISTS (SELECT 1 FROM twitch_broadcasts WHERE ended_at IS NULL AND (CAST(:user AS bigint) IS NULL OR user_id = :user)) AS live'
        );
        $stmt->execute(['user' => $userId]);
        $row = $stmt->fetch();

        $bytes = $userId !== null ? null : (int) $pdo->query(
            "SELECT pg_total_relation_size('twitch_broadcasts') + pg_total_relation_size('chat_viewer_stats')
                  + pg_total_relation_size('chat_viewers')"
        )->fetchColumn();

        return [
            'broadcasts' => (int) $row['broadcasts'],
            'viewers'    => (int) $row['viewers'],
            'rows'       => (int) $row['rows'],
            'last'       => $row['last'],
            'live'       => (bool) $row['live'],
            'bytes'      => $bytes,
        ];
    }

    /** @return list<array{at:string, level:string, message:string, username:?string}> newest first; one user's, or everything */
    public static function recentLog(?int $userId = null, int $limit = 40): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT l.at, l.level, l.message, u.username
               FROM bot_log l
          LEFT JOIN users u ON u.id = l.user_id
              WHERE (CAST(:user AS bigint) IS NULL OR l.user_id = :user)
           ORDER BY l.at DESC, l.id DESC
              LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute(['user' => $userId]);

        return $stmt->fetchAll();
    }

    public static function log(?int $userId, string $level, string $message): void
    {
        Database::connection()->prepare('INSERT INTO bot_log (user_id, level, message) VALUES (?, ?, ?)')
            ->execute([$userId, $level, mb_substr($message, 0, 500)]);
    }

    /** Tells a running bot to reload what changed. */
    public static function notify(): void
    {
        Database::connection()->exec('NOTIFY streamorg_bot');
    }
}
