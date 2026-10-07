<?php
declare(strict_types=1);

/**
 * Administration → Users: list, add, edit, reset passwords and delete.
 *
 * Mirrors bin/create_user.php for new accounts. When the admin leaves a
 * password blank a strong one is generated and shown once, on the next
 * page only, to be passed on to the user.
 *
 * Guard rails: admins cannot demote, deactivate or delete themselves, and
 * the last active administrator can never be removed. Resetting the
 * password of a private vault destroys its codes, so it needs an explicit
 * acknowledgement; deleting needs the username typed back.
 */
final class UserAdminController
{
    public const ROLES = ['user', 'admin'];

    private const GENERATED_KEY = 'admin_new_user_password';

    public static function index(): void
    {
        Auth::requireAdmin();

        $users = Database::connection()->query(
            "SELECT u.id, u.username, u.display_name, u.email, u.role, u.locale, u.timezone, u.is_active,
                    u.vault_mode, u.created_at, u.avatar_path, u.avatar_updated_at, tc.twitch_login,
                    (SELECT max(s.last_seen_at) FROM user_sessions s WHERE s.user_id = u.id) AS last_seen
               FROM users u
          LEFT JOIN twitch_connections tc ON tc.user_id = u.id
           ORDER BY u.created_at DESC, u.id DESC"
        )->fetchAll();

        $generated = $_SESSION[self::GENERATED_KEY] ?? null;
        unset($_SESSION[self::GENERATED_KEY]);

        View::render('admin/users', [
            'users'     => $users,
            'generated' => is_array($generated) ? $generated : null,
            'old'       => (array) ($_SESSION['admin_new_user_old'] ?? []),
            'roles'     => self::ROLES,
            'locales'   => Lang::available(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaultTz' => (string) (Auth::user()['timezone'] ?: Config::get('app.timezone', 'UTC')),
        ], __('ui.nav.users'));

        unset($_SESSION['admin_new_user_old']);
    }

    public static function store(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $input = [
            'username' => trim((string) ($_POST['username'] ?? '')),
            'name'     => trim((string) ($_POST['name'] ?? '')),
            'email'    => trim((string) ($_POST['email'] ?? '')),
            'role'     => (string) ($_POST['role'] ?? 'user'),
            'locale'   => (string) ($_POST['locale'] ?? ''),
            'timezone' => (string) ($_POST['timezone'] ?? ''),
        ];
        $password = (string) ($_POST['password'] ?? '');

        $error = match (true) {
            !preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $input['username'])         => 'ui.message.user_bad_username',
            filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false        => 'ui.message.user_bad_email',
            mb_strlen($input['name']) > 80                                      => 'ui.message.invalid_input',
            !in_array($input['role'], self::ROLES, true)                        => 'ui.message.invalid_input',
            !in_array($input['locale'], Lang::available(), true)                => 'ui.message.invalid_input',
            !in_array($input['timezone'], DateTimeZone::listIdentifiers(), true) => 'ui.message.invalid_input',
            $password !== '' && mb_strlen($password) < 10                       => 'ui.message.password_short',
            default                                                             => null,
        };

        $pdo = Database::connection();

        if ($error === null) {
            $stmt = $pdo->prepare('SELECT username = ?::citext AS same_name FROM users WHERE username = ?::citext OR email = ?::citext LIMIT 1');
            $stmt->execute([$input['username'], $input['username'], $input['email']]);
            $clash = $stmt->fetch();

            if ($clash !== false) {
                $error = $clash['same_name'] ? 'ui.message.user_username_taken' : 'ui.message.user_email_taken';
            }
        }

        if ($error !== null) {
            $_SESSION['admin_new_user_old'] = $input;
            flash('error', __($error));
            redirect('/admin/users#new');
        }

        $generated = $password === '';

        if ($generated) {
            $password = self::generatePassword();
        }

        $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, display_name, locale, timezone, role)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $input['username'],
            $input['email'],
            Auth::hash($password),
            $input['name'] !== '' ? $input['name'] : $input['username'],
            $input['locale'],
            $input['timezone'],
            $input['role'],
        ]);

        if ($generated) {
            $_SESSION[self::GENERATED_KEY] = ['username' => $input['username'], 'password' => $password];
        }

        flash('success', sprintf(__('ui.message.user_created'), $input['username']));
        redirect('/admin/users');
    }

    /** Changes a user's details, role and whether they can sign in. */
    public static function update(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $target = self::target();
        $id     = (int) $target['id'];

        $input = [
            'name'     => trim((string) ($_POST['name'] ?? '')),
            'email'    => trim((string) ($_POST['email'] ?? '')),
            'role'     => (string) ($_POST['role'] ?? ''),
            'locale'   => (string) ($_POST['locale'] ?? ''),
            'timezone' => (string) ($_POST['timezone'] ?? ''),
            'active'   => !empty($_POST['is_active']),
        ];

        $error = match (true) {
            filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false
                && $input['email'] !== $target['email']                         => 'ui.message.user_bad_email',
            mb_strlen($input['name']) > 80                                      => 'ui.message.invalid_input',
            !in_array($input['role'], self::ROLES, true)                        => 'ui.message.invalid_input',
            !in_array($input['locale'], Lang::available(), true)                => 'ui.message.invalid_input',
            !in_array($input['timezone'], DateTimeZone::listIdentifiers(), true) => 'ui.message.invalid_input',
            $id === Auth::id() && ($input['role'] !== 'admin' || !$input['active']) => 'ui.message.user_not_yourself',
            self::isLastAdmin($target) && ($input['role'] !== 'admin' || !$input['active']) => 'ui.message.user_last_admin',
            default                                                             => null,
        };

        if ($error === null) {
            $stmt = Database::connection()->prepare('SELECT 1 FROM users WHERE email = ?::citext AND id <> ?');
            $stmt->execute([$input['email'], $id]);

            if ($stmt->fetchColumn()) {
                $error = 'ui.message.user_email_taken';
            }
        }

        if ($error !== null) {
            flash('error', __($error));
            redirect('/admin/users');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        $pdo->prepare(
            'UPDATE users SET display_name = ?, email = ?, role = ?, locale = ?, timezone = ?, is_active = ?, updated_at = now()
              WHERE id = ?'
        )->execute([
            $input['name'] !== '' ? $input['name'] : $target['username'],
            $input['email'],
            $input['role'],
            $input['locale'],
            $input['timezone'],
            $input['active'] ? 'true' : 'false',
            $id,
        ]);

        $ended = $target['is_active'] && !$input['active'] ? UserSessions::revokeAll($id, 'deactivated') : 0;

        $pdo->commit();

        flash('success', sprintf(__('ui.message.user_updated'), $target['username'])
            . ($ended > 0 ? ' ' . sprintf(__('ui.message.user_sessions_ended'), $ended) : ''));
        redirect('/admin/users');
    }

    /**
     * Sets a new password (typed, or generated and shown once) and signs
     * the user out everywhere. A private vault gets a fresh data key: the
     * codes stored under the old one become unreadable, by design.
     */
    public static function resetPassword(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $target   = self::target();
        $id       = (int) $target['id'];
        $password = (string) ($_POST['password'] ?? '');
        $private  = $target['vault_mode'] === 'private';

        if ($password !== '' && mb_strlen($password) < 10) {
            flash('error', __('ui.message.password_short'));
            redirect('/admin/users');
        }

        if ($private && empty($_POST['accept_loss'])) {
            flash('error', __('ui.message.user_reset_private_confirm'));
            redirect('/admin/users');
        }

        $generated = $password === '';

        if ($generated) {
            $password = self::generatePassword();
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = now() WHERE id = ?')
            ->execute([Auth::hash($password), $id]);

        if ($private) {
            Vault::resetPrivate($id, $password);
        }

        $ended = UserSessions::revokeAll($id, 'password_reset', $id === Auth::id() ? UserSessions::currentId($id) : null);

        $pdo->commit();

        if ($generated) {
            $_SESSION[self::GENERATED_KEY] = ['username' => $target['username'], 'password' => $password];
        }

        flash('success', sprintf(__('ui.message.user_password_reset'), $target['username'])
            . ($ended > 0 ? ' ' . sprintf(__('ui.message.user_sessions_ended'), $ended) : ''));
        redirect('/admin/users');
    }

    /** Deletes an account and everything it owns. The username must be typed back. */
    public static function delete(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $target = self::target();
        $id     = (int) $target['id'];

        $error = match (true) {
            $id === Auth::id()                                                     => 'ui.message.user_not_yourself',
            self::isLastAdmin($target)                                             => 'ui.message.user_last_admin',
            strcasecmp(trim((string) ($_POST['confirm'] ?? '')), $target['username']) !== 0 => 'ui.message.user_delete_confirm',
            default                                                                => null,
        };

        if ($error !== null) {
            flash('error', __($error));
            redirect('/admin/users');
        }

        try {
            TwitchEventSub::unsubscribe($id);
            TwitchUser::disconnect($id);
        } catch (Throwable $e) {
            ErrorLog::note('user delete, Twitch cleanup: ' . $e->getMessage());
        }

        Database::transaction(static function (PDO $pdo) use ($id): void {
            $pdo->prepare('DELETE FROM giveaway_prizes p USING giveaways g WHERE g.id = p.giveaway_id AND g.user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        });
        Avatars::forget($target['avatar_path'] ?? null);

        flash('success', sprintf(__('ui.message.user_deleted'), $target['username']));
        redirect('/admin/users');
    }

    /** The user named by the posted id, or back to the list. */
    private static function target(): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0]);
        $user = $stmt->fetch();

        if ($user === false) {
            flash('error', __('ui.message.not_found'));
            redirect('/admin/users');
        }

        return $user;
    }

    /** Whether this user is the only active administrator left. */
    private static function isLastAdmin(array $user): bool
    {
        if ($user['role'] !== 'admin' || !$user['is_active']) {
            return false;
        }

        return (int) Database::connection()
            ->query("SELECT count(*) FROM users WHERE role = 'admin' AND is_active")
            ->fetchColumn() <= 1;
    }

    /** 16 characters from an alphabet without look-alikes (no 0/O, 1/l/I). */
    private static function generatePassword(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < 16; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return implode('-', str_split($password, 4));
    }
}
