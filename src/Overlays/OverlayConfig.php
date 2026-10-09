<?php
declare(strict_types=1);

/**
 * The global overlay and media settings, set by administrators only
 * (Administration → Overlays) and kept in app_settings: whether overlays
 * are on, which types users may add, where overlays are served and how
 * they get updates, how many a user may have, whether advanced mode may
 * carry CSS of the user's own, and the upload limits of the media library.
 */
final class OverlayConfig
{
    public const DEFAULTS = [
        'overlay.enabled'        => '1',
        'overlay.types_off'      => '',
        'overlay.base_url'       => '',
        'overlay.live_url'       => '',
        'overlay.poll_seconds'   => '3',
        'overlay.max_per_user'   => '20',
        'overlay.custom_css'     => '1',
        'media.sound_max_mb'     => '5',
        'media.image_max_mb'     => '5',
        'media.quota_mb'         => '50',
    ];

    public static function enabled(): bool
    {
        return self::get('overlay.enabled') === '1';
    }

    /**
     * Types switched off for everyone.
     *
     * @return list<string>
     */
    public static function typesOff(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', self::get('overlay.types_off')))));
    }

    /** Whether users may add (and run) overlays of this type. */
    public static function typeAllowed(string $type): bool
    {
        return isset(OverlayTypes::all()[$type]) && !in_array($type, self::typesOff(), true);
    }

    /**
     * Where overlay pages are served: the address set here, otherwise
     * OVERLAY_BASE_URL, otherwise the site's own address (overlays then run
     * sandboxed under /overlay, until they get a domain of their own).
     */
    public static function baseUrl(): string
    {
        $base = trim(self::get('overlay.base_url'))
            ?: trim((string) (getenv('OVERLAY_BASE_URL') ?: ''))
            ?: (string) Config::get('app.base_url', '');

        return rtrim($base !== '' ? $base : rtrim(url('/'), '/'), '/');
    }

    /** The live connection (the bot's WebSocket), or '' to only check for changes. */
    public static function liveUrl(): string
    {
        $url = trim(self::get('overlay.live_url'));

        return preg_match('~^wss?://[^\s]+$~i', $url) ? $url : '';
    }

    /** Whether overlays in advanced mode apply their owner's CSS. */
    public static function customCss(): bool
    {
        return self::get('overlay.custom_css') === '1';
    }

    public static function pollSeconds(): int
    {
        return max(2, min(60, (int) self::get('overlay.poll_seconds')));
    }

    public static function maxPerUser(): int
    {
        return max(1, min(200, (int) self::get('overlay.max_per_user')));
    }

    /** Largest file for this kind of media, in bytes. */
    public static function maxBytes(string $kind): int
    {
        return max(1, min(50, (int) self::get($kind === 'sound' ? 'media.sound_max_mb' : 'media.image_max_mb'))) * 1024 * 1024;
    }

    /** How much media one user may keep, in bytes. */
    public static function quotaBytes(): int
    {
        return max(1, min(2000, (int) self::get('media.quota_mb'))) * 1024 * 1024;
    }

    /**
     * Everything, for the administration page.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $out = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $out[$key] = self::get($key);
        }

        return $out;
    }

    /**
     * Saves the administration form; numbers are kept within their bounds
     * and addresses must be web addresses.
     *
     * @throws UserError naming what is wrong
     */
    public static function save(array $in, int $adminId): void
    {
        $types = array_values(array_intersect(array_keys(OverlayTypes::all()), array_map('strval', (array) ($in['types_on'] ?? []))));
        $off   = array_values(array_diff(array_keys(OverlayTypes::all()), $types));

        foreach (['base_url' => '~^https?://[^\s/]+(/[^\s]*)?$~i', 'live_url' => '~^wss?://[^\s]+$~i'] as $field => $pattern) {
            $value = trim((string) ($in[$field] ?? ''));

            if ($value !== '' && !preg_match($pattern, $value)) {
                throw new UserError(__('ui.message.overlay_bad_' . $field));
            }
        }

        $number = static fn (string $field, int $min, int $max): string => (string) max($min, min($max, (int) ($in[$field] ?? 0)));

        $values = [
            'overlay.enabled'      => !empty($in['enabled']) ? '1' : '0',
            'overlay.custom_css'   => !empty($in['custom_css']) ? '1' : '0',
            'overlay.types_off'    => implode(',', $off),
            'overlay.base_url'     => rtrim(trim((string) ($in['base_url'] ?? '')), '/'),
            'overlay.live_url'     => trim((string) ($in['live_url'] ?? '')),
            'overlay.poll_seconds' => $number('poll_seconds', 2, 60),
            'overlay.max_per_user' => $number('max_per_user', 1, 200),
            'media.sound_max_mb'   => $number('sound_max_mb', 1, 10),
            'media.image_max_mb'   => $number('image_max_mb', 1, 10),
            'media.quota_mb'       => $number('quota_mb', 1, 2000),
        ];

        foreach ($values as $key => $value) {
            Settings::set($key, $value, $adminId);
        }
    }

    private static function get(string $key): string
    {
        return (string) Settings::get($key, self::DEFAULTS[$key]);
    }
}
