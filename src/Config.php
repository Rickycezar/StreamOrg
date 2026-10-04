<?php
declare(strict_types=1);

/**
 * Application settings.
 *
 * Read from config/config.php when that file exists (local development,
 * a hand-managed server). Otherwise built from environment variables, which
 * is how a container platform such as Coolify configures the app:
 *
 *   DATABASE_URL     postgres://user:password@host:5432/dbname?sslmode=require
 *                    (or DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, DB_SSLMODE)
 *   APP_KEY          base64 of 32 random bytes — encrypts credentials and key vaults
 *   APP_ENV          production (default) | development
 *   APP_DEBUG        1 to show errors; only honoured when APP_ENV=development
 *   APP_BASE_URL     https://streamorg.example.com
 *   APP_TIMEZONE     default time zone for users without one (default UTC)
 *   APP_LOCALE       default language for visitors (default en)
 *   CONTACT_EMAIL    preview-access address on the landing page
 *   APP_DOMAIN_LOCALES  language per public domain, e.g.
 *                    streamorg.com.br=pt-BR,streamorg.com=en
 *   TRUSTED_PROXIES  comma-separated IPs/CIDRs of the reverse proxy in front
 *                    of the app, e.g. 10.0.0.0/8 (default: loopback only)
 */
final class Config
{
    private static ?array $data = null;

    public static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = dirname(__DIR__) . '/config/config.php';

        if (is_file($path)) {
            $data = require $path;

            if (!is_array($data)) {
                throw new RuntimeException('config/config.php must return an array.');
            }

            return self::$data = $data;
        }

        return self::$data = self::fromEnvironment();
    }

    /**
     * Dot-path lookup: Config::get('db.host', '127.0.0.1')
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::load();

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** For tests: forget the cached settings. */
    public static function reset(?array $data = null): void
    {
        self::$data = $data;
    }

    /** @return array<string,mixed> the same shape as config/config.php */
    private static function fromEnvironment(): array
    {
        $env = static function (string $name, ?string $default = null): ?string {
            $value = getenv($name);
            return $value === false || $value === '' ? $default : $value;
        };

        $db = [
            'host'     => $env('DB_HOST', '127.0.0.1'),
            'port'     => (int) $env('DB_PORT', '5432'),
            'name'     => $env('DB_NAME', 'streamorg'),
            'user'     => $env('DB_USER', 'streamorg'),
            'password' => $env('DB_PASSWORD'),
            'sslmode'  => $env('DB_SSLMODE', 'prefer'),
        ];

        $url = $env('DATABASE_URL');

        if ($url !== null) {
            $parts = parse_url($url);

            if ($parts === false || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
                throw new RuntimeException('DATABASE_URL must be a postgres:// URL.');
            }

            parse_str($parts['query'] ?? '', $query);

            $db = [
                'host'     => $parts['host'] ?? $db['host'],
                'port'     => (int) ($parts['port'] ?? 5432),
                'name'     => ltrim($parts['path'] ?? '', '/') ?: $db['name'],
                'user'     => isset($parts['user']) ? rawurldecode($parts['user']) : $db['user'],
                'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : $db['password'],
                'sslmode'  => (string) ($query['sslmode'] ?? $db['sslmode']),
            ];
        }

        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) $env('TRUSTED_PROXIES', '')))));

        $domainLocales = [];

        foreach (array_filter(array_map('trim', explode(',', (string) $env('APP_DOMAIN_LOCALES', '')))) as $pair) {
            [$domain, $locale] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');

            if ($domain !== '' && $locale !== '') {
                $domainLocales[strtolower($domain)] = $locale;
            }
        }

        return [
            'db'       => $db,
            'security' => ['app_key' => (string) $env('APP_KEY', '')],
            'app'      => [
                'name'            => 'StreamOrg',
                'env'             => $env('APP_ENV', 'production'),
                'debug'           => in_array(strtolower((string) $env('APP_DEBUG', '0')), ['1', 'true', 'yes', 'on'], true),
                'base_url'        => $env('APP_BASE_URL', ''),
                'timezone'        => $env('APP_TIMEZONE', 'UTC'),
                'locale'          => $env('APP_LOCALE', 'en'),
                'contact_email'   => $env('CONTACT_EMAIL', 'streamorg@outlook.com'),
                'trusted_proxies' => $proxies,
                'domain_locales'  => $domainLocales,
            ],
        ];
    }
}
