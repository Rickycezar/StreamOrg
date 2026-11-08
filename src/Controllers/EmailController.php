<?php
declare(strict_types=1);

/**
 * The pages e-mails lead to, and the ones that send them: confirm an
 * address, stop notification e-mails, "forgot password" and choosing a
 * new password (also the first one, from the welcome e-mail).
 *
 * These pages exist only while e-mail really goes out (Mailer::ready()):
 * otherwise they send people to the sign-in page. Unsubscribing always
 * works. "Forgot password" never tells whether an account exists, and is
 * rate-limited like signing in. A new password over a private vault loses its keys, as an
 * administrator's reset does, and needs the same acknowledgement.
 */
final class EmailController
{
    /** GET /email/verify?t= — confirms the address the link was sent to. */
    public static function verify(): void
    {
        self::requireReady();

        $row = EmailTokens::find(['verify'], (string) ($_GET['t'] ?? ''));

        if ($row === null || !EmailTokens::consume((int) $row['token_id'])) {
            flash('error', __('ui.message.email_link_invalid'));
            redirect(Auth::check() ? '/profile' : '/login');
        }

        Database::connection()->prepare('UPDATE users SET email_verified_at = now() WHERE id = ?')->execute([$row['id']]);

        if ($row['signup_status'] === 'pending') {
            $row['email_verified_at'] = 'now';
            flash('success', __(Signups::confirmed($row) ? 'ui.message.signup_ready' : 'ui.message.signup_email_confirmed'));
            redirect('/login');
        }

        flash('success', __('ui.message.email_verified'));
        redirect(Auth::check() ? '/profile' : '/login');
    }

    /** POST /profile/email/verify — sends the confirmation e-mail again. */
    public static function resendVerification(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $user = Auth::user();

        if (!Mailer::ready() || !empty($user['email_verified_at'])) {
            redirect('/profile');
        }

        $sent = AccountEmails::verify($user) !== null;

        flash($sent ? 'success' : 'error', __($sent ? 'ui.message.email_verify_sent' : 'ui.message.email_not_queued'));
        redirect('/profile');
    }

    /**
     * GET /email/unsubscribe?u=&s= shows a button (link scanners must not
     * turn it off by opening the link); POST turns notification e-mails
     * off, also as the one-click unsubscribe mail clients do.
     */
    public static function unsubscribe(): void
    {
        $userId = (int) ($_GET['u'] ?? $_POST['u'] ?? 0);
        $sig    = (string) ($_GET['s'] ?? $_POST['s'] ?? '');

        if (!Mailer::validUnsubscribe($userId, $sig)) {
            error_page(404);
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Database::connection()->prepare('UPDATE users SET email_notifications = false WHERE id = ?')->execute([$userId]);

            if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
                http_response_code(200);
                exit;
            }

            View::authPage('email/unsubscribe', ['done' => true, 'user' => $userId, 'sig' => $sig], __('ui.email_page.unsubscribe_title'));
            return;
        }

        View::authPage('email/unsubscribe', ['done' => false, 'user' => $userId, 'sig' => $sig], __('ui.email_page.unsubscribe_title'));
    }

    /** GET /password/forgot */
    public static function forgot(): void
    {
        self::requireReady();

        View::authPage('email/forgot', [], __('ui.email_page.forgot_title'));
    }

    /** POST /password/forgot — the same answer whether or not an account matches. */
    public static function sendReset(): void
    {
        Csrf::verify();
        self::requireReady();

        $who     = mb_strtolower(trim((string) ($_POST['who'] ?? '')));
        $subject = 'reset:' . $who;
        $wait    = AuthThrottle::wait('reset', $subject);

        if ($wait > 0) {
            flash('error', AuthThrottle::message($wait));
            redirect('/password/forgot');
        }

        AuthThrottle::record('reset', $subject, false);

        if ($who !== '' && mb_strlen($who) <= 254) {
            $stmt = Database::connection()->prepare('SELECT * FROM users WHERE is_active AND (lower(username) = ? OR email = ?) LIMIT 1');
            $stmt->execute([$who, $who]);
            $user = $stmt->fetch();

            if ($user !== false) {
                AccountEmails::passwordReset($user);
            }
        }

        flash('success', __('ui.message.password_reset_sent'));
        redirect('/login');
    }

    /** GET /password/reset?t= — choose a new password (or the first one). */
    public static function resetForm(): void
    {
        self::requireReady();

        $token = (string) ($_GET['t'] ?? '');
        $row   = EmailTokens::find(['reset', 'welcome'], $token);

        if ($row === null) {
            flash('error', __('ui.message.email_link_invalid'));
            redirect('/login');
        }

        View::authPage('email/reset', [
            'token'   => $token,
            'welcome' => $row['purpose'] === 'welcome',
            'private' => $row['vault_mode'] === 'private',
            'user'    => $row,
            'other'   => Auth::check() && (int) Auth::id() !== (int) $row['id'] ? KeyTransfers::handleOf((int) Auth::id()) : null,
        ], __($row['purpose'] === 'welcome' ? 'ui.email_page.welcome_title' : 'ui.email_page.reset_title'));
    }

    /** POST /password/reset */
    public static function reset(): void
    {
        Csrf::verify();
        self::requireReady();

        $token = (string) ($_POST['t'] ?? '');
        $row   = EmailTokens::find(['reset', 'welcome'], $token);
        $back  = '/password/reset?t=' . rawurlencode($token);

        if ($row === null) {
            flash('error', __('ui.message.email_link_invalid'));
            redirect('/login');
        }

        $new     = (string) ($_POST['new_password'] ?? '');
        $private = $row['vault_mode'] === 'private';

        if (mb_strlen($new) < 10) {
            flash('error', __('ui.message.password_short'));
            redirect($back);
        }
        if ($new !== (string) ($_POST['confirm_password'] ?? '')) {
            flash('error', __('ui.message.password_mismatch'));
            redirect($back);
        }
        if ($private && empty($_POST['accept_loss'])) {
            flash('error', __('ui.message.reset_private_confirm'));
            redirect($back);
        }

        $userId = (int) $row['id'];

        try {
            Database::transaction(static function (PDO $pdo) use ($row, $userId, $new, $private): void {
                if (!EmailTokens::consume((int) $row['token_id'])) {
                    throw new UserError(__('ui.message.email_link_invalid'));
                }

                $pdo->prepare('UPDATE users SET password_hash = ?, email_verified_at = coalesce(email_verified_at, now()), updated_at = now() WHERE id = ?')
                    ->execute([Auth::hash($new), $userId]);

                if ($private) {
                    Vault::resetPrivate($userId, $new);
                }

                UserSessions::revokeAll($userId, 'password_reset', null);
            });
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/login');
        }

        AccountEmails::passwordChanged($row);

        flash('success', __($row['purpose'] === 'welcome' ? 'ui.message.password_first_set' : 'ui.message.password_reset_done'));
        redirect('/login');
    }

    /** Off to the sign-in page while e-mail does not really go out. */
    private static function requireReady(): void
    {
        if (!Mailer::ready()) {
            redirect(Auth::check() ? '/profile' : '/login');
        }
    }
}
