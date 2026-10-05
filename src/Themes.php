<?php
declare(strict_types=1);

/**
 * Theme families: each has a light and a dark variant, and the user picks
 * a mode — light, dark, or auto (follow the device's setting).
 *
 * The variants are the [data-theme="…"] blocks in app.css. The server
 * renders the variant for the chosen mode; for auto it renders the light
 * one and public/assets/theme.js swaps to the dark one before the first
 * paint when the device prefers dark, and again whenever that changes.
 * See 026_theme_families_and_avatars.sql.
 */
final class Themes
{
    /** family => [light variant, dark variant], in the order they are offered. */
    public const FAMILIES = [
        'classic'  => ['light', 'dark'],
        'ember'    => ['sepia', 'ember'],
        'moss'     => ['moss', 'kelp'],
        'blossom'  => ['blush', 'violet'],
        'coral'    => ['coral-light', 'dim'],
        'harbour'  => ['harbour-light', 'harbour'],
        'contrast' => ['contrast', 'midnight'],
    ];

    public const MODES = ['light', 'dark', 'auto'];

    public const DEFAULT_FAMILY = 'classic';
    public const DEFAULT_MODE   = 'auto';

    public static function isFamily(string $family): bool
    {
        return isset(self::FAMILIES[$family]);
    }

    public static function isMode(string $mode): bool
    {
        return in_array($mode, self::MODES, true);
    }

    /**
     * The attributes for <html>: the variant to paint first, both variants
     * and the mode, for theme.js.
     *
     * @return array{variant:string, light:string, dark:string, mode:string}
     */
    public static function forUser(?array $user): array
    {
        $family = (string) ($user['theme'] ?? '');
        $mode   = (string) ($user['theme_mode'] ?? '');

        $family = self::isFamily($family) ? $family : self::DEFAULT_FAMILY;
        $mode   = self::isMode($mode) ? $mode : self::DEFAULT_MODE;

        [$light, $dark] = self::FAMILIES[$family];

        return [
            'variant' => $mode === 'dark' ? $dark : $light,
            'light'   => $light,
            'dark'    => $dark,
            'mode'    => $mode,
        ];
    }

    /** The data attributes for <html>, escaped. */
    public static function htmlAttributes(?array $user): string
    {
        $t = self::forUser($user);

        return 'data-theme="' . e($t['variant']) . '" data-theme-light="' . e($t['light'])
            . '" data-theme-dark="' . e($t['dark']) . '" data-theme-mode="' . e($t['mode']) . '"';
    }
}
