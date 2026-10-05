<?php
declare(strict_types=1);

/**
 * Administration → Users: the list of accounts and a form to add one.
 *
 * Mirrors bin/create_user.php for new accounts. When the admin leaves the
 * password blank a strong one is generated and shown once, on the next
 * page only, to be passed on to the new user.
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
                    u.vault_mode, u.created_at, tc.twitch_login,
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
