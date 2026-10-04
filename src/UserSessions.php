<?php
declare(strict_types=1);

/**
 * Server-side record of signed-in sessions (see 016_user_sessions.sql).
 *
 * The PHP session carries a random token; the row holding its hash is what
 * makes the session valid. Deleting or revoking the row signs that browser
 * out on its next request, wherever it is.
 */
final class UserSessions
{
    public const REASONS = ['logout', 'revoked', 'password_changed', 'password_reset', 'expired'];

    private const TOKEN = 'streamorg_session_token';

    /** How often last_seen_at is written, so every request is not an UPDATE. */
    private const TOUCH_EVERY = 60;

    /** Bounds and default for the idle limit, in minutes: 3 days by default. */
    public const IDLE_DEFAULT = 4320;
    public const IDLE_MIN     = 5;
    public const IDLE_MAX     = 129600;

    /**
     * Minutes of inactivity after which a session ends. Set by an admin on
     * /admin/settings. Auth::start() sizes the session cookie and PHP's
     * session garbage collection from the same value, so all three agree.
     */
    public static function idleMinutes(): int
    {
        $minutes = (int) Settings::get('session_idle_minutes', (string) self::IDLE_DEFAULT);

        return $minutes > 0 ? max(self::IDLE_MIN, min(self::IDLE_MAX, $minutes)) : self::IDLE_DEFAULT;
    }

    /**
     * Pushes the session cookie's expiry forward. PHP only sends the cookie
     * when a session starts, so without this it would expire a fixed time
     * after sign-in however active the user was.
     */
    public static function extendCookies(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent() || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $expires = time() + self::idleMinutes() * 60;
        $p = session_get_cookie_params();

        setcookie(session_name(), session_id(), [
            'expires'  => $expires,
            'path'     => $p['path'] ?: '/',
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);

        Vault::extendCookie($expires);
    }

    /** Records a new sign-in and ties it to the current PHP session. */
    public static function begin(int $userId): void
    {
        $token = bin2hex(random_bytes(32));

        Database::connection()
            ->prepare('INSERT INTO user_sessions (user_id, token_hash, ip, user_agent) VALUES (?, ?, ?, ?)')
            ->execute([$userId, hash('sha256', $token), self::ip(), self::agent()]);

        $_SESSION[self::TOKEN] = $token;
    }

    /**
     * The live row behind this request, or null when there is none: never
     * recorded, revoked from elsewhere, or idle too long. An idle row is
     * marked expired here so the security page does not keep listing it.
     *
     * @return array<string,mixed>|null
     */
    public static function current(int $userId): ?array
    {
        $token = $_SESSION[self::TOKEN] ?? null;

        if (!is_string($token) || $token === '') {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, last_seen_at, revoked_at,
                    last_seen_at < now() - make_interval(mins => ?) AS idle,
                    last_seen_at < now() - make_interval(secs => ?) AS stale
               FROM user_sessions
              WHERE token_hash = ? AND user_id = ?'
        );
        $stmt->execute([self::idleMinutes(), self::TOUCH_EVERY, hash('sha256', $token), $userId]);
        $row = $stmt->fetch();

        if ($row === false || $row['revoked_at'] !== null) {
            return null;
        }

        if ($row['idle']) {
            self::revoke($userId, (int) $row['id'], 'expired');
            return null;
        }

        if ($row['stale']) {
            Database::connection()
                ->prepare('UPDATE user_sessions SET last_seen_at = now(), ip = ?, user_agent = ? WHERE id = ?')
                ->execute([self::ip(), self::agent(), $row['id']]);

            self::extendCookies();
        }

        return $row;
    }

    public static function currentId(int $userId): ?int
    {
        $row = self::current($userId);

        return $row === null ? null : (int) $row['id'];
    }

    /** Ends this request's session row, if any. */
    public static function endCurrent(string $reason): void
    {
        $token = $_SESSION[self::TOKEN] ?? null;
        unset($_SESSION[self::TOKEN]);

        if (!is_string($token) || $token === '') {
            return;
        }

        Database::connection()
            ->prepare('UPDATE user_sessions SET revoked_at = now(), revoked_reason = ?
                        WHERE token_hash = ? AND revoked_at IS NULL')
            ->execute([$reason, hash('sha256', $token)]);
    }

    /** Ends one session, only if it belongs to $userId. Returns whether it did. */
    public static function revoke(int $userId, int $sessionId, string $reason = 'revoked'): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE user_sessions SET revoked_at = now(), revoked_reason = ?
              WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$reason, $sessionId, $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Ends every live session of a user except $keep (pass null to end them
     * all). Returns how many were ended.
     */
    public static function revokeAll(int $userId, string $reason, ?int $keep = null): int
    {
        $stmt = Database::connection()->prepare(
            'UPDATE user_sessions SET revoked_at = now(), revoked_reason = ?
              WHERE user_id = ? AND revoked_at IS NULL AND id IS DISTINCT FROM ?'
        );
        $stmt->execute([$reason, $userId, $keep]);

        return $stmt->rowCount();
    }

    /** @return list<array<string,mixed>> live sessions, most recently used first */
    public static function active(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, ip, user_agent, created_at, last_seen_at
               FROM user_sessions
              WHERE user_id = ? AND revoked_at IS NULL
                AND last_seen_at >= now() - make_interval(mins => ?)
           ORDER BY last_seen_at DESC'
        );
        $stmt->execute([$userId, self::idleMinutes()]);

        return $stmt->fetchAll();
    }

    /**
     * Ended sessions, newest first — lets a user spot a sign-in they do not
     * recognise even after it is gone.
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $userId, int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, ip, user_agent, created_at, last_seen_at,
                    COALESCE(revoked_at, last_seen_at + make_interval(mins => :idle1)) AS ended_at,
                    COALESCE(revoked_reason, 'expired') AS reason
               FROM user_sessions
              WHERE user_id = :user
                AND (revoked_at IS NOT NULL OR last_seen_at < now() - make_interval(mins => :idle2))
           ORDER BY ended_at DESC
              LIMIT :limit"
        );
        $stmt->bindValue('idle1', self::idleMinutes(), PDO::PARAM_INT);
        $stmt->bindValue('idle2', self::idleMinutes(), PDO::PARAM_INT);
        $stmt->bindValue('user', $userId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * A readable "Browser on OS" from a user agent. Deliberately coarse:
     * enough to recognise your own devices, not a fingerprint.
     */
    public static function describe(?string $agent): string
    {
        $agent = (string) $agent;

        if ($agent === '') {
            return '?';
        }

        $browsers = [
            'Edg/'     => 'Edge',
            'OPR/'     => 'Opera',
            'Vivaldi/' => 'Vivaldi',
            'Firefox/' => 'Firefox',
            'Chrome/'  => 'Chrome',
            'Safari/'  => 'Safari',
            'curl/'    => 'curl',
        ];

        $browser = '?';

        foreach ($browsers as $needle => $name) {
            if (str_contains($agent, $needle)) {
                $browser = $name;
                break;
            }
        }

        $systems = [
            'Android'    => 'Android',
            'iPhone'     => 'iPhone',
            'iPad'       => 'iPad',
            'Windows'    => 'Windows',
            'Mac OS X'   => 'macOS',
            'CrOS'       => 'ChromeOS',
            'Linux'      => 'Linux',
        ];

        foreach ($systems as $needle => $name) {
            if (str_contains($agent, $needle)) {
                return $browser . ' · ' . $name;
            }
        }

        return $browser;
    }

    private static function ip(): ?string
    {
        return client_ip();
    }

    private static function agent(): ?string
    {
        $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        return $agent === '' ? null : mb_substr($agent, 0, 500);
    }
}
