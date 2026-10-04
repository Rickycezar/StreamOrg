<?php
declare(strict_types=1);

/**
 * Session-backed authentication.
 *
 * Passwords are hashed with Argon2id where available, falling back to
 * whatever PASSWORD_DEFAULT is. Rehashing happens transparently on login
 * when the algorithm or cost has moved on.
 */
final class Auth
{
    private const SESSION_KEY = 'streamorg_user_id';

    /**
     * Verified against when the username does not exist, so that path costs
     * the same as a wrong password. One per algorithm, matching hash():
     * a bcrypt dummy against Argon2id hashes (or the reverse) would differ
     * by ~100 ms — enough to tell from the network which usernames exist.
     */
    private const DUMMY_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$aEJ0a2tNcDNmc3V5Q3p4MA$7WD4EEm6sKuglVhTgs23dU39yePQyCYGB5WP9XXc0ms';
    private const DUMMY_BCRYPT   = '$2y$10$5tYbjhja69AUcvSbtRjYs.CbzReFCowNZot4wKx/p1bUSfO7MAhLO';

    private static ?array $user = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = UserSessions::idleMinutes() * 60;

        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => request_is_https(),
        ]);

        $dir = dirname(__DIR__) . '/tmp/sessions';

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        session_save_path($dir);
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        session_start();
    }

    public static function hash(#[\SensitiveParameter] string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        return password_hash($password, $algo);
    }

    /**
     * Verifies credentials and starts the session. Returns false for a bad
     * username, a bad password or a deactivated account — deliberately
     * without distinguishing which.
     */
    public static function attempt(string $username, #[\SensitiveParameter] string $password): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1'
        );
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user === false || !$user['is_active']) {
            password_verify($password, defined('PASSWORD_ARGON2ID') ? self::DUMMY_ARGON2ID : self::DUMMY_BCRYPT);
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $update = Database::connection()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $update->execute([self::hash($password), $user['id']]);
        }

        Database::connection()
            ->prepare('UPDATE users SET last_login_at = now() WHERE id = ?')
            ->execute([$user['id']]);

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        self::$user = null;

        Csrf::rotate();

        UserSessions::begin((int) $user['id']);

        Vault::forget();

        if (!Vault::unlock((int) $user['id'], $password)) {
            error_log("StreamOrg: user #{$user['id']} signed in but their private vault did not unlock.");
        }

        return true;
    }

    public static function logout(): void
    {
        UserSessions::endCurrent('logout');
        Vault::forget();
        self::$user = null;
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();
    }

    /**
     * A per-session token safe to hand to JavaScript.
     *
     * Client-side preferences that must reset when the session does are
     * stored against this rather than the session id, which is httpOnly
     * for good reason and should not be copied into localStorage.
     */
    public static function clientMarker(): string
    {
        if (empty($_SESSION['client_marker'])) {
            $_SESSION['client_marker'] = bin2hex(random_bytes(8));
        }

        return (string) $_SESSION['client_marker'];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $id = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_int($id)) {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT u.*, sp.code AS channel_platform_code
               FROM users u
          LEFT JOIN streaming_platforms sp ON sp.id = u.channel_platform_id
              WHERE u.id = ? AND u.is_active'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if ($user === false) {
            return null;
        }

        if (UserSessions::current($id) === null) {
            unset($_SESSION[self::SESSION_KEY]);
            Vault::forget();
            return null;
        }

        return self::$user = $user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && $user['role'] === 'admin';
    }

    /** Redirects to the login screen unless someone is signed in. */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('/login');
        }
    }

    /** 403s anyone who is not an admin. */
    public static function requireAdmin(): void
    {
        self::requireLogin();

        if (!self::isAdmin()) {
            http_response_code(403);
            echo '<h1>403</h1><p>' . e(Lang::t('ui.message.access_denied')) . '</p>';
            exit;
        }
    }
}
