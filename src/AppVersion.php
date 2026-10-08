<?php
declare(strict_types=1);

/**
 * The version of StreamOrg that is running, from the VERSION file at the
 * root of the project (semantic versioning: major.minor.patch). Each
 * release is also tagged in git as v<version>, and described in
 * CHANGELOG.md.
 */
final class AppVersion
{
    private static ?string $version = null;

    /** e.g. "0.6.0"; "dev" when the file is missing. */
    public static function current(): string
    {
        if (self::$version === null) {
            $file = dirname(__DIR__) . '/VERSION';
            $text = is_readable($file) ? trim((string) file_get_contents($file)) : '';
            self::$version = preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $text) ? $text : 'dev';
        }

        return self::$version;
    }
}
