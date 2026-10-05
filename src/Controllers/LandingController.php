<?php
declare(strict_types=1);

/**
 * The public home page: what StreamOrg is, a way in for people who have an
 * account, and an address to ask for preview access. Rendered without the
 * app's layout — visitors have no menu to show.
 */
final class LandingController
{
    public static function index(): void
    {
        $email = (string) Config::get('app.contact_email', '');

        $subject = __('ui.landing.mail_subject');
        $body    = __('ui.landing.mail_body');

        echo View::partial('landing', [
            'signedIn' => Auth::check(),
            'email'    => $email,
            'art'      => self::art(),
            'testimonials' => Testimonials::forLocale(Lang::locale()),
            'mailto'   => $email === '' ? null
                : 'mailto:' . $email . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body),
        ]);
    }

    /**
     * Box art from the catalogue for the page's illustrations, newest
     * releases first. Empty on a fresh install: the page draws placeholders.
     *
     * @return list<array{title:string, url:string}>
     */
    private static function art(): array
    {
        try {
            $rows = Database::connection()->query(
                "SELECT g.title, i.path, i.updated_at
                   FROM game_images i JOIN games g ON g.id = i.game_id
                  WHERE i.kind = 'portrait'
               ORDER BY g.release_date DESC NULLS LAST, g.id DESC
                  LIMIT 5"
            )->fetchAll();
        } catch (PDOException) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'title' => (string) $r['title'],
            'url'   => GameImages::publicUrl($r['path'], (string) $r['updated_at']),
        ], $rows);
    }
}
