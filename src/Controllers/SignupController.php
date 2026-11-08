<?php
declare(strict_types=1);

/**
 * GET/POST /signup — a streamer creates their own account (Signups), only
 * while e-mail is live: the address must be confirmed, and an
 * administrator approves the account before it works. Rate-limited per
 * address like signing in; a field hidden from people catches bots.
 */
final class SignupController
{
    public static function form(): void
    {
        self::requireOpen();

        View::authPage('signup/form', ['old' => (array) ($_SESSION['signup_old'] ?? [])], __('ui.signup.title'));
        unset($_SESSION['signup_old']);
    }

    public static function store(): void
    {
        Csrf::verify();
        self::requireOpen();

        $subject = 'ip:' . (client_ip() ?? 'unknown');
        $wait    = AuthThrottle::wait('signup', $subject);

        if ($wait > 0) {
            flash('error', AuthThrottle::message($wait));
            redirect('/signup');
        }

        AuthThrottle::record('signup', $subject, false);

        $input = [
            'username' => (string) ($_POST['username'] ?? ''),
            'name'     => (string) ($_POST['name'] ?? ''),
            'email'    => (string) ($_POST['email'] ?? ''),
            'password' => (string) ($_POST['password'] ?? ''),
            'confirm'  => (string) ($_POST['confirm_password'] ?? ''),
            'channel'  => (string) ($_POST['channel'] ?? ''),
            'timezone' => (string) ($_POST['timezone'] ?? ''),
        ];

        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            View::authPage('signup/sent', ['email' => $input['email']], __('ui.signup.title'));
            return;
        }

        if (empty($_POST['accept_privacy'])) {
            $_SESSION['signup_old'] = array_diff_key($input, ['password' => 1, 'confirm' => 1]);
            flash('error', __('ui.message.signup_accept_privacy'));
            redirect('/signup');
        }

        try {
            $user = Signups::create($input, Lang::locale());
        } catch (UserError $e) {
            $_SESSION['signup_old'] = array_diff_key($input, ['password' => 1, 'confirm' => 1]);
            flash('error', $e->getMessage());
            redirect('/signup');
        }

        View::authPage('signup/sent', ['email' => (string) $user['email']], __('ui.signup.title'));
    }

    /** Sign-up exists only while e-mail really goes out, and never for someone signed in. */
    private static function requireOpen(): void
    {
        if (Auth::check()) {
            redirect('/dashboard');
        }
        if (!Mailer::ready()) {
            redirect('/login');
        }
    }
}
