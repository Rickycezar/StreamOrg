<?php
declare(strict_types=1);

/** Raised when a private vault is needed but its password has not been given. */
final class VaultLocked extends RuntimeException
{
}

/**
 * Per-user encryption of game key codes.
 *
 * Each user has a random 256-bit data key. Codes are encrypted with it
 * (AES-256-GCM, bound to the owner's id so a ciphertext copied onto
 * another user's row fails to decrypt), and duplicate detection uses an
 * HMAC keyed from it. The data key is stored wrapped in one of two ways —
 * see db/migrations/015_key_vault.sql:
 *
 *   managed  wrapped with the application key; always unwrappable here.
 *   private  wrapped with a PBKDF2 key from the user's password; only
 *            unwrappable while that password is at hand.
 *
 * Switching mode or changing the password only re-wraps the data key; the
 * codes themselves are never re-encrypted.
 *
 * A private data key has to survive between requests without the password.
 * It is kept in the session, encrypted with a random key that lives only
 * in a cookie, so neither the session files on the server nor the cookie
 * on its own is enough to read it.
 */
final class Vault
{
    public const MODES = ['managed', 'private'];

    private const CIPHER      = 'aes-256-gcm';
    private const CODE_PREFIX = 'vk1:';
    private const WRAP_PREFIX = 'p1';
    private const ITERATIONS  = 600000;
    private const COOKIE      = 'streamorg_vault';
    private const SESSION     = 'streamorg_vault';

    /** @var array<int,string> data keys unwrapped during this request */
    private static array $keys = [];

    public static function encryptCode(int $userId, string $code): string
    {
        return self::CODE_PREFIX . self::seal(self::key($userId), $code, 'code:' . $userId);
    }

    /**
     * Returns the plain code, or null when it cannot be decrypted — which
     * for a private vault means it was stored under a data key that was
     * lost with a reset password.
     *
     * A value without the prefix is returned as-is, so a code stored before
     * encryption stays readable until bin/encrypt_keys.php converts it.
     */
    public static function decryptCode(int $userId, string $stored): ?string
    {
        if (!self::isEncrypted($stored)) {
            return $stored;
        }

        return self::open(self::key($userId), substr($stored, strlen(self::CODE_PREFIX)), 'code:' . $userId);
    }

    /** Keyed hash used for the uniqueness index in place of the code itself. */
    public static function hashCode(int $userId, string $code): string
    {
        $indexKey = hash_hkdf('sha256', self::key($userId), 32, 'streamorg:key-index');

        return hash_hmac('sha256', trim($code), $indexKey);
    }

    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::CODE_PREFIX);
    }

    /**
     * The user's data key.
     *
     * @throws VaultLocked for a private vault not unlocked in this session
     */
    public static function key(int $userId): string
    {
        if (isset(self::$keys[$userId])) {
            return self::$keys[$userId];
        }

        $user = self::row($userId);

        if ($user['vault_mode'] === 'private') {
            $key = self::fromSession($userId);

            if ($key === null) {
                throw new VaultLocked('The key vault is locked.');
            }

            return self::$keys[$userId] = $key;
        }

        if ($user['vault_key_app'] === null) {
            return self::$keys[$userId] = self::createManaged($userId);
        }

        return self::$keys[$userId] = self::unwrapManaged((string) $user['vault_key_app']);
    }

    public static function isUnlocked(int $userId): bool
    {
        try {
            self::key($userId);
            return true;
        } catch (VaultLocked) {
            return false;
        }
    }

    public static function mode(int $userId): string
    {
        return (string) self::row($userId)['vault_mode'];
    }

    /**
     * Unlocks a private vault with its password, for this request and — in
     * a web request — for the rest of the session. A managed vault needs no
     * password, so this simply succeeds for one.
     *
     * Returns false when the password does not unwrap the key.
     */
    public static function unlock(int $userId, #[\SensitiveParameter] string $password): bool
    {
        $user = self::row($userId);

        if ($user['vault_mode'] !== 'private') {
            return true;
        }

        $key = self::unwrapPassword($userId, (string) $user['vault_key_pw'], $password);

        if ($key === null) {
            return false;
        }

        self::$keys[$userId] = $key;
        self::remember($userId, $key);

        return true;
    }

    /**
     * For command-line scripts: asks for the password when the user's vault
     * is private, without echoing it. Does nothing for a managed vault.
     */
    public static function promptUnlock(int $userId, string $username): bool
    {
        if (self::row($userId)['vault_mode'] !== 'private') {
            return true;
        }

        fwrite(STDERR, "The key vault of '{$username}' is private. Password: ");

        $tty = stream_isatty(STDIN);

        if ($tty) {
            shell_exec('stty -echo');
        }

        $password = rtrim((string) fgets(STDIN), "\r\n");

        if ($tty) {
            shell_exec('stty echo');
        }

        fwrite(STDERR, "\n");

        return self::unlock($userId, $password);
    }

    /** Keeps the vault cookie alive alongside the session cookie. */
    public static function extendCookie(int $expires): void
    {
        $value = (string) ($_COOKIE[self::COOKIE] ?? '');

        if ($value !== '') {
            self::setCookie($value, $expires);
        }
    }

    /** Drops the session copy of a private data key. Called on logout. */
    public static function forget(): void
    {
        self::$keys = [];
        unset($_SESSION[self::SESSION]);

        if (isset($_COOKIE[self::COOKIE])) {
            self::setCookie('', time() - 42000);
            unset($_COOKIE[self::COOKIE]);
        }
    }

    /** Managed → private. The caller has already verified the password. */
    public static function makePrivate(int $userId, #[\SensitiveParameter] string $password): void
    {
        $key = self::key($userId);

        $stmt = Database::connection()->prepare(
            "UPDATE users SET vault_mode = 'private', vault_key_pw = ?, vault_key_app = NULL
              WHERE id = ? AND vault_mode = 'managed'"
        );
        $stmt->execute([self::wrapPassword($userId, $key, $password), $userId]);

        if ($stmt->rowCount() === 1) {
            self::remember($userId, $key);
        }
    }

    /** Private → managed. Needs the password, since only it unwraps the key. */
    public static function makeManaged(int $userId, #[\SensitiveParameter] string $password): bool
    {
        $user = self::row($userId);

        if ($user['vault_mode'] !== 'private') {
            return true;
        }

        $key = self::unwrapPassword($userId, (string) $user['vault_key_pw'], $password);

        if ($key === null) {
            return false;
        }

        Database::connection()
            ->prepare("UPDATE users SET vault_mode = 'managed', vault_key_app = ?, vault_key_pw = NULL WHERE id = ?")
            ->execute([self::wrapManaged($key), $userId]);

        self::$keys[$userId] = $key;
        unset($_SESSION[self::SESSION]);

        return true;
    }

    /**
     * Re-wraps a private data key under a new password. A managed vault
     * does not depend on the password, so there is nothing to do.
     */
    public static function changePassword(int $userId, #[\SensitiveParameter] string $old, #[\SensitiveParameter] string $new): bool
    {
        $user = self::row($userId);

        if ($user['vault_mode'] !== 'private') {
            return true;
        }

        $key = self::unwrapPassword($userId, (string) $user['vault_key_pw'], $old);

        if ($key === null) {
            return false;
        }

        Database::connection()
            ->prepare('UPDATE users SET vault_key_pw = ? WHERE id = ?')
            ->execute([self::wrapPassword($userId, $key, $new), $userId]);

        self::$keys[$userId] = $key;

        return true;
    }

    /**
     * A password set without knowing the old one — an admin reset. For a
     * private vault the old data key is unrecoverable by design, so a fresh
     * one is wrapped under the new password. Codes stored under the old key
     * stay in the table but can no longer be decrypted.
     */
    public static function resetPrivate(int $userId, #[\SensitiveParameter] string $newPassword): void
    {
        $key = random_bytes(32);

        Database::connection()
            ->prepare("UPDATE users SET vault_key_pw = ? WHERE id = ? AND vault_mode = 'private'")
            ->execute([self::wrapPassword($userId, $key, $newPassword), $userId]);

        self::$keys[$userId] = $key;
    }

    /** @return array{vault_mode:string, vault_key_app:?string, vault_key_pw:?string} */
    private static function row(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT vault_mode, vault_key_app, vault_key_pw FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new RuntimeException("No user #{$userId}.");
        }

        return $row;
    }

    /**
     * First use of a managed vault. Guarded by the WHERE clause so two
     * concurrent requests cannot each create a key and lose one's codes:
     * the loser re-reads the winner's key.
     */
    private static function createManaged(int $userId): string
    {
        $key = random_bytes(32);

        $stmt = Database::connection()->prepare(
            "UPDATE users SET vault_key_app = ?
              WHERE id = ? AND vault_mode = 'managed' AND vault_key_app IS NULL"
        );
        $stmt->execute([self::wrapManaged($key), $userId]);

        if ($stmt->rowCount() === 1) {
            return $key;
        }

        $wrapped = self::row($userId)['vault_key_app'];

        if ($wrapped === null) {
            throw new VaultLocked('The key vault changed mode while being created.');
        }

        return self::unwrapManaged((string) $wrapped);
    }

    private static function wrapManaged(string $key): string
    {
        return (string) Crypto::encrypt(base64_encode($key));
    }

    private static function unwrapManaged(string $wrapped): string
    {
        $plain = Crypto::decrypt($wrapped);
        $key   = $plain === null ? false : base64_decode($plain, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Could not unwrap a managed vault key: is security.app_key unchanged?');
        }

        return $key;
    }

    /** Format: p1$<iterations>$<salt>$<sealed key>, so the cost can rise later. */
    private static function wrapPassword(int $userId, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $password): string
    {
        $salt = random_bytes(16);
        $kek  = hash_pbkdf2('sha256', $password, $salt, self::ITERATIONS, 32, true);

        return implode('$', [
            self::WRAP_PREFIX,
            self::ITERATIONS,
            base64_encode($salt),
            self::seal($kek, $key, 'vault:' . $userId),
        ]);
    }

    private static function unwrapPassword(int $userId, string $wrapped, #[\SensitiveParameter] string $password): ?string
    {
        $parts = explode('$', $wrapped);

        if (count($parts) !== 4 || $parts[0] !== self::WRAP_PREFIX) {
            return null;
        }

        $salt = base64_decode($parts[2], true);

        if ($salt === false) {
            return null;
        }

        $iterations = (int) $parts[1];

        if ($iterations < 100000) {
            return null;
        }

        $kek = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $key = self::open($kek, $parts[3], 'vault:' . $userId);

        return $key !== null && strlen($key) === 32 ? $key : null;
    }

    /** Keeps a private data key for the session: sealed in it, keyed by a cookie. */
    private static function remember(int $userId, string $key): void
    {
        $cookieKey = random_bytes(32);

        $_SESSION[self::SESSION] = [
            'user' => $userId,
            'key'  => self::seal($cookieKey, $key, 'session:' . $userId),
        ];

        $encoded = rtrim(strtr(base64_encode($cookieKey), '+/', '-_'), '=');
        self::setCookie($encoded, time() + UserSessions::idleMinutes() * 60);
        $_COOKIE[self::COOKIE] = $encoded;
    }

    private static function fromSession(int $userId): ?string
    {
        $held   = $_SESSION[self::SESSION] ?? null;
        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');

        if (!is_array($held) || ($held['user'] ?? null) !== $userId || $cookie === '') {
            return null;
        }

        $cookieKey = base64_decode(strtr($cookie, '-_', '+/'), true);

        if ($cookieKey === false || strlen($cookieKey) !== 32) {
            return null;
        }

        $key = self::open($cookieKey, (string) $held['key'], 'session:' . $userId);

        return $key !== null && strlen($key) === 32 ? $key : null;
    }

    private static function setCookie(string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $p = session_get_cookie_params();

        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => $p['path'] ?: '/',
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** AES-256-GCM; returns base64(iv . tag . ciphertext). */
    private static function seal(string $key, string $plain, string $aad): string
    {
        $iv  = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    private static function open(string $key, string $sealed, string $aad): ?string
    {
        $raw = base64_decode($sealed, true);

        if ($raw === false || strlen($raw) < 28) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($raw, 28),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            $aad,
        );

        return $plain === false ? null : $plain;
    }
}
