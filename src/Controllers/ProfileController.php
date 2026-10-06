<?php
declare(strict_types=1);

/**
 * The signed-in user's own account, split into tabs: personal data,
 * password, appearance, defaults (stream schedule) and security (key
 * vault and sessions).
 *
 * Separate from the admin user tools that do not exist yet: everything
 * here is scoped to Auth::id() and needs no special privilege.
 */
final class ProfileController
{
    /** Tab path => lang key of its label, in menu order. */
    public const TABS = [
        '/profile'            => 'ui.label.profile_details',
        '/profile/password'   => 'ui.label.change_password',
        '/profile/appearance' => 'ui.label.profile_appearance',
        '/profile/defaults'   => 'ui.label.profile_defaults',
        '/profile/security'   => 'ui.label.profile_security',
    ];

    /** The tabs this user sees: Chat bot only once an admin has set the bot up. */
    public static function tabs(): array
    {
        return self::TABS + (ChatBot::isAvailable() ? ['/profile/bot' => 'ui.nav.chat_bot'] : []);
    }

    /** Renders a tab owned by another controller inside the profile frame. */
    public static function renderTab(string $path, string $template, array $data = []): void
    {
        self::tab($path, $template, $data);
    }

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
        ], __('ui.nav.profile') . ' · ' . __(self::tabs()[$path] ?? 'ui.nav.profile'));
    }

    public static function index(): void
    {
        Auth::requireLogin();

        $pdo = Database::connection();

        $userId = (int) Auth::id();
        $log    = $pdo->prepare('SELECT kind, detail, at FROM twitch_live_log WHERE user_id = ? ORDER BY at DESC, id DESC LIMIT 10');
        $log->execute([$userId]);

        self::tab('/profile', 'profile/details', [
            'twitch'           => TwitchUser::connection($userId),
            'avatar'           => Avatars::url(Auth::user()),
            'twitchConfigured' => Twitch::isConfigured(),
            'trackingAvailable' => TwitchEventSub::isAvailable(),
            'trackingActive'   => TwitchEventSub::isActive($userId),
            'trackingLog'      => $log->fetchAll(),
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

        self::tab('/profile/appearance', 'profile/appearance', ['families' => Themes::FAMILIES, 'modes' => Themes::MODES]);
    }

    public static function defaults(): void
    {
        Auth::requireLogin();

        self::tab('/profile/defaults', 'profile/defaults', [
            'schedule'       => StreamSchedule::forUser((int) Auth::id()),
            'contentMinutes' => ContentDefaults::minutes((int) Auth::id()),
            'prefixes'       => ContentDefaults::prefixes((int) Auth::id()),
            'counters'       => TitleCounters::forUser((int) Auth::id()),
        ]);
    }

    /** Saves the content defaults: usual content length, title prefixes and their counters. */
    public static function saveContentDefaults(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $userId  = (int) Auth::id();
        $minutes = ContentDefaults::minutesFromInput((string) ($_POST['content_minutes'] ?? ''));

        try {
            if ($minutes === null) {
                throw new UserError(__('ui.message.content_length_invalid'));
            }

            $counters = TitleCounters::fromInput((array) ($_POST['counter'] ?? []));
            $prefixes = ContentDefaults::prefixesFromInput(
                (array) ($_POST['prefix'] ?? []),
                array_column($counters, 'name'),
                TitleCounters::renames(TitleCounters::forUser($userId), $counters)
            );

            ContentDefaults::save($userId, $minutes, $prefixes, $counters);
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/profile/defaults');
        }

        flash('success', __('ui.message.saved'));
        redirect('/profile/defaults');
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

    /** Saves the theme family and mode from the appearance tab. */
    public static function theme(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $family = (string) ($_POST['theme'] ?? '');
        $mode   = (string) ($_POST['theme_mode'] ?? '');

        if (!Themes::isFamily($family) || !Themes::isMode($mode)) {
            flash('error', __('ui.message.invalid_input'));
            redirect('/profile/appearance');
        }

        Database::connection()
            ->prepare('UPDATE users SET theme = ?, theme_mode = ? WHERE id = ?')
            ->execute([$family, $mode, Auth::id()]);

        flash('success', __('ui.message.saved'));
        redirect('/profile/appearance');
    }

    /** Upload a new profile picture. */
    public static function avatar(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $file = $_FILES['avatar'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            flash('error', __(($file['error'] ?? null) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? null) === UPLOAD_ERR_FORM_SIZE
                ? 'ui.message.avatar_too_big' : 'ui.message.avatar_not_image'));
            redirect('/profile');
        }

        try {
            Avatars::store((int) Auth::id(), (string) file_get_contents($file['tmp_name']));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/profile');
        }

        flash('success', __('ui.message.avatar_saved'));
        redirect('/profile');
    }

    /** Copies the Twitch profile picture. */
    public static function avatarFromTwitch(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        try {
            Avatars::fromTwitch((int) Auth::id(), Auth::user());
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/profile');
        }

        flash('success', __('ui.message.avatar_from_twitch'));
        redirect('/profile');
    }

    public static function avatarRemove(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        Avatars::remove((int) Auth::id());

        flash('success', __('ui.message.avatar_removed'));
        redirect('/profile');
    }

    /** AJAX, from the user menu: switches light / dark / auto without a reload. */
    public static function themeMode(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $mode = (string) ($_POST['mode'] ?? '');

        if (!Themes::isMode($mode)) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        Database::connection()->prepare('UPDATE users SET theme_mode = ? WHERE id = ?')->execute([$mode, Auth::id()]);

        json_response(['ok' => true]);
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

        $back = (string) ($_POST['back'] ?? '');
        $back = $back === '/profile/security' || preg_match('#^/giveaways/show\?id=\d+$#', $back) ? $back : '/keys';

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

    /**
     * Where Twitch sends the user back, with a code or with an error. The
     * same address serves viewer sign-ins and the chat bot's account (told
     * apart by their state), so the Twitch console needs no extra redirect
     * URL.
     */
    public static function twitchCallback(): void
    {
        if (Viewers::isViewerCallback((string) ($_GET['state'] ?? ''))) {
            ViewerController::callback((string) ($_GET['code'] ?? ''));
        }

        if (ChatBot::isBotCallback((string) ($_GET['state'] ?? ''))) {
            BotController::callback((string) ($_GET['code'] ?? ''));
        }

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

        Viewers::linkMatchingUser((string) (TwitchUser::connection((int) Auth::id())['twitch_user_id'] ?? ''));
        ChatBot::recheck((int) Auth::id());

        if (TwitchEventSub::isAvailable() && !TwitchEventSub::subscribe((int) Auth::id())) {
            error_log('StreamOrg EventSub subscribe: ' . TwitchEventSub::lastError());
        }

        flash('success', sprintf(__('ui.message.twitch_connected'), $result['login']));
        redirect('/profile');
    }

    public static function twitchDisconnect(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        TwitchEventSub::unsubscribe((int) Auth::id());
        TwitchUser::disconnect((int) Auth::id());

        flash('success', __('ui.message.twitch_disconnected'));
        redirect('/profile');
    }

    /** Turns live tracking (the EventSub subscriptions) on or off. */
    public static function twitchTracking(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $userId = (int) Auth::id();

        if (($_POST['tracking'] ?? '') !== 'on') {
            TwitchEventSub::unsubscribe($userId);
            flash('success', __('ui.message.tracking_off'));
            redirect('/profile');
        }

        if (!TwitchEventSub::isAvailable() || TwitchUser::connection($userId) === null) {
            flash('error', __('ui.message.tracking_unavailable'));
            redirect('/profile');
        }

        if (!TwitchEventSub::subscribe($userId)) {
            error_log('StreamOrg EventSub subscribe: ' . TwitchEventSub::lastError());
            flash('error', __('ui.message.tracking_failed'));
            redirect('/profile');
        }

        flash('success', __('ui.message.tracking_on'));
        redirect('/profile');
    }
}
