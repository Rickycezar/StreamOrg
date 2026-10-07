<?php
declare(strict_types=1);

/**
 * The visitors' language switcher (landing and sign-in pages).
 *
 * On a domain with a fixed language (Administration → Settings) there is
 * no switcher, and a stray /language link changes nothing.
 *
 * The choice is remembered in a cookie for a year and beats the domain's
 * default language; a signed-in user's own profile setting still beats it
 * (see Lang::resolve()). Choosing nothing — or clearing cookies — goes back
 * to the domain's language.
 */
final class LocaleController
{
    public static function switch(): void
    {
        $locale = (string) ($_GET['to'] ?? '');

        if (Lang::switchable() && in_array($locale, Lang::available(), true)) {
            setcookie(Lang::COOKIE, $locale, [
                'expires'  => time() + 365 * 86400,
                'path'     => '/',
                'secure'   => request_is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        $back = (string) ($_GET['back'] ?? '/');

        if (!preg_match('#^/(?![/\\\\])#', $back) || preg_match('/[\r\n]/', $back)) {
            $back = '/';
        }

        header('Location: ' . url($back), true, 302);
        exit;
    }

    /**
     * The switcher's links, for a view: locale => [label, href, current].
     *
     * @return array<string, array{label:string, href:string, current:bool}>
     */
    public static function links(): array
    {
        if (!Lang::switchable()) {
            return [];
        }

        $links = [];
        $back  = self::here();

        foreach (Lang::available() as $locale) {
            $links[$locale] = [
                'label'   => Lang::t('ui.label.language_name', $locale),
                'href'    => url('/language') . '?' . http_build_query(['to' => $locale, 'back' => $back]),
                'current' => $locale === Lang::locale(),
            ];
        }

        return $links;
    }

    /**
     * The current page to come back to, with its query string (a claim
     * link's token, a search) and without the app's base path, which
     * url() adds again.
     */
    public static function here(): string
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $base = rtrim((string) Config::get('app.base_path', ''), '/');

        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }

        $query = (string) parse_url($uri, PHP_URL_QUERY);

        return $path . ($query !== '' ? '?' . $query : '');
    }
}
