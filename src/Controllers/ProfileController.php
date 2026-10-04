<?php
declare(strict_types=1);

/**
 * The signed-in user's own account, split into tabs: personal data,
 * password, appearance and security (key vault and sessions).
 *
 * Separate from the admin user tools that do not exist yet: everything
 * here is scoped to Auth::id() and needs no special privilege.
 */
final class ProfileController
{
    public const THEMES = [
        'light', 'sepia', 'moss', 'blush', 'contrast',
        'dim',
        'dark', 'midnight', 'violet', 'harbour', 'ember', 'kelp',
    ];

    /** Tab path => lang key of its label, in menu order. */
    public const TABS = [
        '/profile'            => 'ui.label.profile_details',
        '/profile/password'   => 'ui.label.change_password',
        '/profile/appearance' => 'ui.label.profile_appearance',
        '/profile/security'   => 'ui.label.profile_security',
    ];

    /** Renders one tab inside the shared profile frame and sub-menu. */
    private static function tab(string $path, string $template, array $data = []): void
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([Auth::id()]);

        View::render('profile/frame', [
            'user'     => $stmt->fetch(),
            'tab'      => $path,
            'tabView'  => $template,
            'tabData'  => $data,
        ], __('ui.nav.profile') . ' · ' . __(self::TABS[$path]));
    }

    public static function index(): void
    {
        Auth::requireLogin();

        $pdo = Database::connection();

        self::tab('/profile', 'profile/details', [
            'twitch'           => TwitchUser::connection((int) Auth::id()),
            'twitchConfigured' => Twitch::isConfigured(),
            'platforms' => $pdo->query('SELECT id, code FROM streaming_platforms WHERE is_enabled ORDER BY sort_order')->fetchAll(),
            'locales'   => Lang::available(),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public static function passwordPage(): void
    {
        Auth::requireLogin();

        self::tab('/profile/password', 'profile/password');
    }

    public static function appearance(): void
    {
        Auth::requireLogin();

        self::tab('/profile/appearance', 'profile/appearance', ['themes' => self::THEMES]);
    }

    public static function security(): void
    {
        Auth::requireLogin();

        $userId = (int) Auth::id();

        self::tab('/profile/security', 'profile/security', [
            'vaultLocked' => !Vault::isUnlocked($userId),
            'sessions'    => UserSessions::active($userId),
            'recent'      => UserSessions::recent($userId),
            'currentId'   => UserSessions::currentId($userId),
            'idleMinutes' => UserSessions::idleMinutes(),
        ]);
    }

    /**
     * Checks the signed-in user's password under the guessing limits (see
     * AuthThrottle): refused outright while locked out, and every outcome
     * is counted. Redirects to $back with a message when it fails.
     */
    private static function confirmPassword(string $scope, #[\SensitiveParameter] string $password, string $back): void
    {
        $subject = (string) Auth::id();
        $wait    = AuthThrottle::wait($scope, $subject);

        if ($wait > 0) {
            flash('error', AuthThrottle::message($wait));
            redirect($back);
        }

        $ok = $scope === 'vault'
            ? Vault::unlock((int) Auth::id(), $password)
            : password_verify($password, (string) Auth::user()['password_hash']);

        AuthThrottle::record($scope, $subject, $ok);

        if (!$ok) {
            flash('error', __('ui.message.password_wrong'));
            redirect($back);
        }
    }

    /** Saves the theme on its own, from the appearance tab. */
    public static function theme(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $theme = (string) ($_POST['theme'] ?? '');

        if (!in_array($theme, self::THEMES, true)) {
            flash('error', __('ui.message.invalid_input'));
            redirect('/profile/appearance');
        }

        Database::connection()
            ->prepare('UPDATE users SET theme = ? WHERE id = ?')
            ->execute([$theme, Auth::id()]);

        flash('success', __('ui.message.saved'));
        redirect('/profile/appearance');
    }

    /** Signs out one of your sessions. Ending the current one is a logout. */
    public static function revokeSession(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $userId    = (int) Auth::id();
        $sessionId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($sessionId === UserSessions::currentId($userId)) {
            Auth::logout();
            redirect('/login');
        }

        if (!$sessionId || !UserSessions::revoke($userId, $sessionId)) {
            flash('error', __('ui.message.not_found'));
            redirect('/profile/security');
        }

        flash('success', __('ui.message.session_revoked'));
        redirect('/profile/security');
    }

    public static function revokeOtherSessions(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $userId = (int) Auth::id();
        $count  = UserSessions::revokeAll($userId, 'revoked', UserSessions::currentId($userId));

        flash('success', sprintf(__('ui.message.sessions_revoked'), $count));
        redirect('/profile/security');
    }

    public static function update(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $email = trim((string) ($_POST['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', __('ui.message.invalid_email'));
            redirect('/profile');
        }

        $locale   = (string) ($_POST['locale'] ?? 'en');
        $timezone = (string) ($_POST['timezone'] ?? 'UTC');

        if (!in_array($locale, Lang::available(), true)) {
            $locale = 'en';
        }

        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $timezone = 'UTC';
        }

        $pdo = Database::connection();

        $platformId = null;
        $platform   = trim((string) ($_POST['channel_platform'] ?? ''));

        if ($platform !== '') {
            $stmt = $pdo->prepare('SELECT id FROM streaming_platforms WHERE code = ? AND is_enabled');
            $stmt->execute([$platform]);
            $found = $stmt->fetchColumn();
            $platformId = $found === false ? null : (int) $found;
        }

        $stmt = $pdo->prepare(
            'UPDATE users
                SET display_name = :name, email = :email, locale = :locale,
                    timezone = :tz,
                    channel_platform_id = :platform, channel_handle = :handle
              WHERE id = :id'
        );

        try {
            $stmt->execute([
                'name'     => trim((string) ($_POST['display_name'] ?? '')) ?: null,
                'email'    => $email,
                'locale'   => $locale,
                'tz'       => $timezone,
                'platform' => $platformId,
                'handle'   => trim((string) ($_POST['channel_handle'] ?? '')) ?: null,
                'id'       => Auth::id(),
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                flash('error', __('ui.message.email_taken'));
                redirect('/profile');
            }

            throw $e;
        }

        flash('success', __('ui.message.saved'));
        redirect('/profile');
    }

    /** Changing the password needs the current one, even when signed in. */
    public static function password(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $user = Auth::user();

        self::confirmPassword('password', $current, '/profile/password');

        if (mb_strlen($new) < 10) {
            flash('error', __('ui.message.password_short'));
            redirect('/profile/password');
        }

        if ($new !== $confirm) {
            flash('error', __('ui.message.password_mismatch'));
            redirect('/profile/password');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        if (!Vault::changePassword((int) Auth::id(), $current, $new)) {
            $pdo->rollBack();
            flash('error', __('ui.message.vault_unwrap_failed'));
            redirect('/profile/password');
        }

        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([Auth::hash($new), Auth::id()]);

        $userId = (int) Auth::id();
        $ended  = UserSessions::revokeAll($userId, 'password_changed', UserSessions::currentId($userId));

        $pdo->commit();

        session_regenerate_id(true);

        flash('success', __('ui.message.password_changed')
            . ($ended > 0 ? ' ' . sprintf(__('ui.message.sessions_revoked'), $ended) : ''));
        redirect('/profile/password');
    }

    /**
     * Switches the key vault between managed and private.
     *
     * Both directions need the current password: going private wraps the
     * data key with it, and going back is only possible by unwrapping it.
     * Going private also needs an explicit acknowledgement, because from
     * then on a forgotten password cannot be recovered by anyone.
     */
    public static function vault(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $mode     = (string) ($_POST['vault_mode'] ?? '');
        $password = (string) ($_POST['current_password'] ?? '');
        $user     = Auth::user();

        if (!in_array($mode, Vault::MODES, true)) {
            flash('error', __('ui.message.invalid_input'));
            redirect('/profile/security');
        }

        self::confirmPassword('password', $password, '/profile/security');

        if ($mode === 'private') {
            if (empty($_POST['accept_loss'])) {
                flash('error', __('ui.message.vault_accept_required'));
                redirect('/profile/security');
            }

            Vault::makePrivate((int) $user['id'], $password);
            flash('success', __('ui.message.vault_now_private'));
            redirect('/profile/security');
        }

        if (!Vault::makeManaged((int) $user['id'], $password)) {
            flash('error', __('ui.message.vault_unwrap_failed'));
            redirect('/profile/security');
        }

        flash('success', __('ui.message.vault_now_managed'));
        redirect('/profile/security');
    }

    /** Re-enters the password for a private vault whose session copy is gone. */
    public static function unlockVault(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $back = ($_POST['back'] ?? '') === '/profile/security' ? '/profile/security' : '/keys';

        self::confirmPassword('vault', (string) ($_POST['password'] ?? ''), $back);

        flash('success', __('ui.message.vault_unlocked'));
        redirect($back);
    }

    /** Sends the user to Twitch to approve StreamOrg editing their channel. */
    public static function twitchConnect(): void
    {
        Auth::requireLogin();

        if (!Twitch::isConfigured()) {
            flash('error', __('ui.message.twitch_not_configured'));
            redirect('/profile');
        }

        header('Location: ' . TwitchUser::authorizeUrl());
        exit;
    }

    /** Where Twitch sends the user back, with a code or with an error. */
    public static function twitchCallback(): void
    {
        Auth::requireLogin();

        $code  = (string) ($_GET['code'] ?? '');
        $state = (string) ($_GET['state'] ?? '');

        if ($code === '') {
            flash('error', __('ui.message.twitch_declined'));
            redirect('/profile');
        }

        $result = TwitchUser::complete((int) Auth::id(), $code, $state);

        if ($result === null) {
            error_log('StreamOrg Twitch connect: ' . TwitchUser::lastError());
            flash('error', __('ui.message.twitch_connect_failed'));
            redirect('/profile');
        }

        $user = Auth::user();

        if (empty($user['channel_handle'])) {
            Database::connection()->prepare(
                "UPDATE users
                    SET channel_platform_id = (SELECT id FROM streaming_platforms WHERE code = 'twitch'),
                        channel_handle = ?
                  WHERE id = ?"
            )->execute([$result['login'], Auth::id()]);
        }

        flash('success', sprintf(__('ui.message.twitch_connected'), $result['login']));
        redirect('/profile');
    }

    public static function twitchDisconnect(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        TwitchUser::disconnect((int) Auth::id());

        flash('success', __('ui.message.twitch_disconnected'));
        redirect('/profile');
    }
}
