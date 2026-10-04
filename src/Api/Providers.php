<?php
declare(strict_types=1);

/**
 * Registry of catalogue providers, hydrated from api_settings.
 *
 * The set of providers is fixed in code; the database only stores whether
 * each is switched on and what credentials it holds. A provider with no
 * row behaves as disabled.
 */
final class Providers
{
    private const CLASSES = [
        'steam' => SteamProvider::class,
        'igdb'  => IgdbProvider::class,
        'rawg'  => RawgProvider::class,
        'omdb'  => OmdbProvider::class,
    ];

    /** @var array<string, Provider>|null */
    private static ?array $instances = null;

    /** @return array<string, Provider> every known provider, configured or not */
    public static function all(): array
    {
        if (self::$instances !== null) {
            return self::$instances;
        }

        $rows = Database::connection()
            ->query('SELECT * FROM api_settings')
            ->fetchAll();

        $settings = array_column($rows, null, 'provider');

        foreach ($settings as &$row) {
            foreach (['api_key', 'client_id', 'client_secret'] as $field) {
                $row[$field] = Crypto::decrypt($row[$field] ?? null);
            }
        }

        unset($row);

        $instances = [];

        foreach (self::CLASSES as $code => $class) {
            $instances[$code] = new $class($settings[$code] ?? []);
        }

        return self::$instances = $instances;
    }

    /** @return array<string, Provider> only those usable right now */
    public static function available(): array
    {
        return array_filter(self::all(), static fn (Provider $p): bool => $p->isAvailable());
    }

    public static function get(string $code): ?Provider
    {
        return self::all()[$code] ?? null;
    }

    /** True when at least one provider can be searched — gates the import UI. */
    public static function anyAvailable(): bool
    {
        return self::available() !== [];
    }

    /** Drops cached instances so a settings save takes effect immediately. */
    public static function forget(): void
    {
        self::$instances = null;
    }
}
