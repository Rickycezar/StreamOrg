<?php
declare(strict_types=1);

/**
 * Resolves language keys against lang/<locale>.php.
 *
 * Two kinds of key are used across the app:
 *   Lang::t('ui.nav.games')                interface string
 *   Lang::code('key_status', $row['status'])  a code stored in the database
 *
 * A key missing from the active locale falls back to the default locale;
 * if it is missing there too the key itself is returned, which makes gaps
 * obvious on screen instead of rendering an empty label.
 *
 * Codes from the lookup tables (key sites, platforms, genres) can also have
 * labels entered by admins (CodeLabels), used where a file has none:
 * file, then database label, for the active locale and then the default.
 */
final class Lang
{
    private const DEFAULT_LOCALE = 'en';

    private static string $locale = self::DEFAULT_LOCALE;

    private static bool $switchable = true;

    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * Every locale with a file in lang/, e.g. ['en', 'pt-BR'].
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $files = glob(dirname(__DIR__) . '/lang/*.php') ?: [];

        return array_values(array_map(
            static fn (string $f): string => basename($f, '.php'),
            $files,
        ));
    }

    /** Cookie holding a visitor's explicit language choice (the switcher). */
    public const COOKIE = 'streamorg_lang';

    /**
     * The language for this request, most specific first:
     *
     *   1. the signed-in user's own setting (profile);
     *   2. a visitor's explicit choice from the language switcher (cookie);
     *   3. the domain — e.g. streamorg.com.br is Portuguese, streamorg.com
     *      English (app.domain_locales);
     *   4. the browser's Accept-Language, on any other host (localhost…);
     *   5. the app default.
     *
     * A domain marked as having a fixed language (Administration →
     * Settings) always uses its own, whatever the profile or the cookie
     * say: there is no language switch there.
     *
     * @param array<string,string> $domainLocales host => locale
     * @param list<string> $fixedDomains domains whose language cannot be changed
     */
    public static function resolve(
        ?string $userLocale,
        ?string $chosen,
        string $host,
        string $acceptLanguage,
        array $domainLocales,
        string $default,
        array $fixedDomains = [],
    ): string {
        $available = self::available();
        $fixed     = self::fixedFor($host, $domainLocales, $fixedDomains);

        if ($fixed !== null && in_array($fixed, $available, true)) {
            return $fixed;
        }

        foreach ([$userLocale, $chosen, self::forHost($host, $domainLocales)] as $candidate) {
            if ($candidate !== null && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return self::negotiate($acceptLanguage, $default);
    }

    /**
     * The locale configured for a host: an exact match, a www. or other
     * subdomain of a configured domain, ignoring any port. Null when the
     * host is not one of them.
     *
     * @param array<string,string> $domainLocales host => locale
     */
    public static function forHost(string $host, array $domainLocales): ?string
    {
        $host = strtolower(trim(preg_replace('/:\d+$/', '', $host) ?? ''));

        uksort($domainLocales, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($domainLocales as $domain => $locale) {
            $domain = strtolower(trim((string) $domain));

            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return (string) $locale;
            }
        }

        return null;
    }

    /**
     * The fixed language of a host, when its domain is one of $fixedDomains;
     * null when visitors may choose.
     *
     * @param array<string,string> $domainLocales host => locale
     * @param list<string> $fixedDomains
     */
    public static function fixedFor(string $host, array $domainLocales, array $fixedDomains): ?string
    {
        $fixed = array_intersect_key(
            $domainLocales,
            array_flip(array_map(static fn (string $d): string => strtolower(trim($d)), $fixedDomains))
        );

        return $fixed === [] ? null : self::forHost($host, $fixed);
    }

    /** Whether visitors on this request's host may change the language (no fixed language there). */
    public static function switchable(): bool
    {
        return self::$switchable;
    }

    /** Set once per request by the front controller. */
    public static function setSwitchable(bool $switchable): void
    {
        self::$switchable = $switchable;
    }

    /**
     * The public address that speaks a language: the domain set to it
     * (a fixed one first), otherwise the base URL. For links sent to other
     * people, like giveaway claim links.
     *
     * @param array<string,string> $domainLocales host => locale
     * @param list<string> $fixedDomains
     */
    public static function baseUrlFor(?string $locale, array $domainLocales, array $fixedDomains, string $baseUrl): string
    {
        $fixed   = array_map(static fn (string $d): string => strtolower(trim($d)), $fixedDomains);
        $matches = array_keys(array_filter($domainLocales, static fn (string $l): bool => $l === $locale));
        usort($matches, static fn (string $a, string $b): int => (int) in_array($b, $fixed, true) <=> (int) in_array($a, $fixed, true));

        return $matches !== [] ? 'https://' . strtolower($matches[0]) : rtrim($baseUrl, '/');
    }

    /**
     * Picks the best available locale for an Accept-Language header, for
     * visitors who are not signed in: an exact match first ("pt-BR"), then
     * the language alone ("pt" -> "pt-BR"), in the browser's order.
     */
    public static function negotiate(string $header, string $fallback): string
    {
        $available = self::available();
        $byLower   = array_combine(array_map('strtolower', $available), $available);
        $wanted    = [];

        foreach (explode(',', $header) as $i => $part) {
            [$tag, $q] = array_pad(explode(';q=', trim($part)), 2, '1');
            $tag = strtolower(trim($tag));

            if ($tag !== '' && $tag !== '*') {
                $wanted[] = [$tag, (float) $q, $i];
            }
        }

        usort($wanted, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        foreach ($wanted as [$tag]) {
            if (isset($byLower[$tag])) {
                return $byLower[$tag];
            }

            $language = explode('-', $tag)[0];

            foreach ($byLower as $lower => $locale) {
                if (explode('-', $lower)[0] === $language) {
                    return $locale;
                }
            }
        }

        return $fallback;
    }

    public static function t(string $key, ?string $locale = null): string
    {
        $locale = $locale ?? self::$locale;

        $value = self::lookup($key, $locale) ?? self::dbLabel($key, $locale);

        if ($value === null && $locale !== self::DEFAULT_LOCALE) {
            $value = self::lookup($key, self::DEFAULT_LOCALE) ?? self::dbLabel($key, self::DEFAULT_LOCALE);
        }

        return is_string($value) ? $value : $key;
    }

    /** An admin-entered label for "group.code", when the group is a lookup table. */
    private static function dbLabel(string $key, string $locale): ?string
    {
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2 || !isset(CodeLabels::GROUPS[$parts[0]])) {
            return null;
        }

        return CodeLabels::get($parts[0], $parts[1], $locale);
    }

    /**
     * Label for a code stored in the database.
     *
     *     Lang::code('key_status', 'for_giveaway')  ->  'For giveaway'
     */
    public static function code(string $group, ?string $code, ?string $locale = null): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        return self::t("{$group}.{$code}", $locale);
    }

    /**
     * Whole group as code => label, for building <select> options.
     *
     * @return array<string, string>
     */
    public static function group(string $group, ?string $locale = null): array
    {
        $locale = $locale ?? self::$locale;

        $values = self::lookup($group, $locale);

        if (!is_array($values) && $locale !== self::DEFAULT_LOCALE) {
            $values = self::lookup($group, self::DEFAULT_LOCALE);
        }

        $values = is_array($values) ? $values : [];

        if (isset(CodeLabels::GROUPS[$group])) {
            $values += CodeLabels::group($group, $locale) + CodeLabels::group($group, self::DEFAULT_LOCALE);
        }

        return $values;
    }

    private static function lookup(string $key, string $locale): mixed
    {
        $data = self::table($locale);

        foreach (explode('.', $key) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private static function table(string $locale): array
    {
        if (isset(self::$loaded[$locale])) {
            return self::$loaded[$locale];
        }

        if (!preg_match('/^[A-Za-z]{2}(-[A-Za-z0-9]{2,8})?$/', $locale)) {
            return self::$loaded[$locale] = [];
        }

        $path = dirname(__DIR__) . "/lang/{$locale}.php";

        if (!is_file($path)) {
            return self::$loaded[$locale] = [];
        }

        $data = require $path;

        return self::$loaded[$locale] = is_array($data) ? $data : [];
    }
}
