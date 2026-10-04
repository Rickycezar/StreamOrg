<?php
declare(strict_types=1);

/**
 * Authenticated encryption for credentials held in the database.
 *
 * AES-256-GCM, so a tampered ciphertext fails to decrypt rather than
 * quietly returning rubbish. The key is derived with PBKDF2-SHA256 from
 * the application key in config/config.php and a fixed salt.
 *
 * On the fixed salt: a salt exists to stop one rainbow table covering many
 * secrets, and it is not itself secret. Fixing it is only safe because the
 * entropy comes from app_key, which is random per install and lives in a
 * git-ignored file. That separation is the point — someone who gets a dump
 * of the database still cannot read the credentials without the key file.
 *
 * Losing config/config.php means losing the stored credentials. They can
 * be re-entered; nothing else depends on them.
 */
final class Crypto
{
    private const SALT       = 'iforgotthekeys';
    private const CIPHER     = 'aes-256-gcm';
    private const ITERATIONS = 120000;
    private const PREFIX     = 'enc:v1:';

    private static ?string $key = null;

    /** True when an application key is configured. */
    public static function isReady(): bool
    {
        $key = trim((string) Config::get('security.app_key', ''));

        return $key !== '' && !str_starts_with($key, 'CHANGE_ME');
    }

    /**
     * Encrypts a value. Null and empty strings pass through unchanged so
     * callers can store "no credential" without a ciphertext for it.
     */
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }

        if (!self::isReady()) {
            throw new RuntimeException('security.app_key is not set in config/config.php.');
        }

        $iv  = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Decrypts a value.
     *
     * Anything without the version prefix is returned as-is: credentials
     * stored before encryption was introduced stay readable instead of
     * turning into garbage, and a value typed straight into the database
     * still works.
     */
    public static function decrypt(?string $blob): ?string
    {
        if ($blob === null || $blob === '' || !str_starts_with($blob, self::PREFIX)) {
            return $blob;
        }

        if (!self::isReady()) {
            return null;
        }

        $raw = base64_decode(substr($blob, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($raw, 28),
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
        );

        return $plain === false ? null : $plain;
    }

    /** True when a stored value is already ciphertext. */
    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /** Masks a secret for display: never echo one back into a form. */
    public static function hint(?string $plain): string
    {
        if ($plain === null || $plain === '') {
            return '';
        }

        $len = strlen($plain);

        return $len <= 8
            ? str_repeat('•', $len)
            : substr($plain, 0, 3) . str_repeat('•', min(12, $len - 6)) . substr($plain, -3);
    }

    private static function key(): string
    {
        return self::$key ??= hash_pbkdf2(
            'sha256',
            (string) Config::get('security.app_key', ''),
            self::SALT,
            self::ITERATIONS,
            32,
            true,
        );
    }
}
