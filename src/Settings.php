<?php
declare(strict_types=1);

/**
 * Runtime settings stored in app_settings (see 017_app_settings.sql).
 *
 * Read once per request and cached. A missing table or row falls back to
 * the caller's default, so code asking for a setting keeps working on a
 * database that has not been migrated yet.
 */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            try {
                self::$cache = Database::connection()
                    ->query('SELECT key, value FROM app_settings')
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (PDOException) {
                self::$cache = [];
            }
        }

        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, string $value, ?int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO app_settings (key, value, updated_by) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE
                SET value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = now()'
        )->execute([$key, $value, $userId]);

        self::$cache = null;
    }
}
