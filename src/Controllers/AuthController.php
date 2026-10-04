<?php
declare(strict_types=1);

final class AuthController
{
    public static function showLogin(): void
    {
        if (Auth::check()) {
            redirect('/dashboard');
        }

        View::render('login', [
            'username' => (string) ($_SESSION['login_username'] ?? ''),
        ], __('ui.action.login'));
    }

    public static function login(): void
    {
        Csrf::verify();

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            flash('error', __('ui.message.login_required_fields'));
            $_SESSION['login_username'] = $username;
            redirect('/login');
        }

        $subject = AuthThrottle::loginSubject($username);
        $wait    = AuthThrottle::wait('login', $subject);

        if ($wait > 0) {
            flash('error', AuthThrottle::message($wait));
            $_SESSION['login_username'] = $username;
            redirect('/login');
        }

        $ok = Auth::attempt($username, $password);
        AuthThrottle::record('login', $subject, $ok);

        if (!$ok) {
            flash('error', __('ui.message.login_failed'));
            $_SESSION['login_username'] = $username;
            redirect('/login');
        }

        unset($_SESSION['login_username']);
        redirect('/dashboard');
    }

    public static function logout(): void
    {
        Csrf::verify();
        Auth::logout();
        redirect('/login');
    }
}
