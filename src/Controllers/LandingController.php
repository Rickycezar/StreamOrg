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
            'theme'    => Auth::user()['theme'] ?? null,
            'email'    => $email,
            'mailto'   => $email === '' ? null
                : 'mailto:' . $email . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body),
        ]);
    }
}
