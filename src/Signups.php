<?php
declare(strict_types=1);

/**
 * Streamers signing up themselves, while e-mail is live (Mailer::ready()).
 *
 * A sign-up creates an inactive account waiting for approval
 * (signup_status 'pending') and sends the address-confirmation e-mail.
 * Once the address is confirmed, the administrators are told; one of
 * them approves it (the account works from then on, and the person gets
 * an e-mail saying so) or declines it (the account is deleted, with a
 * short e-mail). With auto-approval on, confirming the address is
 * enough. Sign-ups whose address is not confirmed within
 * STALE_DAYS are removed. Accounts created by administrators never wait.
 */
final class Signups
{
    public const STALE_DAYS = 7;

    /**
     * Creates a waiting account from the sign-up form and sends the
     * confirmation e-mail.
     *
     * @param array{username:string, name:string, email:string, password:string, confirm:string, channel:string, timezone:string} $input
     * @throws UserError when something is wrong with the form
     */
    public static function create(array $input, string $locale): array
    {
        $username = trim($input['username']);
        $email    = mb_strtolower(trim($input['email']));
        $name     = trim((string) preg_replace('/\s+/', ' ', $input['name']));
        $channel  = strtolower(ltrim(trim($input['channel']), '@'));
        $timezone = in_array($input['timezone'], DateTimeZone::listIdentifiers(), true) ? $input['timezone'] : (string) Config::get('app.timezone', 'UTC');

        $error = match (true) {
            !preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)                     => 'ui.message.user_bad_username',
            filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254 => 'ui.message.user_bad_email',
            $name === '' || mb_strlen($name) > 80                                  => 'ui.message.signup_bad_name',
            $channel !== '' && !preg_match('/^[a-z0-9_]{3,25}$/', $channel)        => 'ui.message.signup_bad_channel',
            mb_strlen($input['password']) < 10                                     => 'ui.message.password_short',
            $input['password'] !== $input['confirm']                               => 'ui.message.password_mismatch',
            default                                                                => null,
        };

        if ($error !== null) {
            throw new UserError(__($error));
        }

        self::purgeStale();

        $pdo  = Database::connection();
        $stmt = $pdo->prepare('SELECT username = ?::citext AS same_name FROM users WHERE username = ?::citext OR email = ?::citext LIMIT 1');
        $stmt->execute([$username, $username, $email]);
        $clash = $stmt->fetch();

        if ($clash !== false) {
            throw new UserError(__($clash['same_name'] ? 'ui.message.user_username_taken' : 'ui.message.signup_email_taken'));
        }

        $stmt = $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, display_name, locale, timezone, role, is_active, signup_status,
                                channel_platform_id, channel_handle)
             VALUES (?, ?, ?, ?, ?, ?, 'user', false, 'pending',
                     CASE WHEN ? <> '' THEN (SELECT id FROM streaming_platforms WHERE code = 'twitch') END, NULLIF(?, ''))
             RETURNING *"
        );
        $stmt->execute([$username, $email, Auth::hash($input['password']), $name, $locale, $timezone, $channel, $channel]);
        $user = $stmt->fetch();

        AccountEmails::verify($user);

        return $user;
    }

    /** Whether sign-ups are let in as soon as their address is confirmed (Administration → Users). */
    public static function autoApprove(): bool
    {
        return Settings::get('signup.auto_approve', '0') === '1';
    }

    /**
     * The address of a waiting account was confirmed: with auto-approval
     * the account is let in at once; otherwise it waits. The
     * administrators are told either way.
     *
     * @return bool whether the account works now
     */
    public static function confirmed(array $user): bool
    {
        if (($user['signup_status'] ?? null) !== 'pending') {
            return true;
        }

        $approved = self::autoApprove() && self::approve((int) $user['id']);

        Notifications::system(Notifications::adminIds(), $approved ? 'signup_auto' : 'signup_new', [(string) $user['username']],
            $approved ? '/admin/users' : '/admin/users#signups', 'news');

        return $approved;
    }

    /** @return list<array> the accounts waiting for approval, newest first */
    public static function pending(): array
    {
        return Database::connection()->query(
            "SELECT id, username, display_name, email, email_verified_at, channel_handle, locale, created_at
               FROM users WHERE signup_status = 'pending' ORDER BY created_at DESC"
        )->fetchAll();
    }

    /** Lets a waiting account in, and tells the person. */
    public static function approve(int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE users SET is_active = true, signup_status = NULL, updated_at = now()
              WHERE id = ? AND signup_status = 'pending' AND email_verified_at IS NOT NULL RETURNING *"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user === false) {
            return false;
        }

        Mailer::queue('signup_approved', ['user' => $user], ['username' => (string) $user['username']],
            rtrim(public_base_url((string) $user['locale']), '/') . url('/login'));

        return true;
    }

    /** Turns a sign-up down: the waiting account is deleted, and a confirmed address is told. */
    public static function decline(int $userId): bool
    {
        $stmt = Database::connection()->prepare("SELECT * FROM users WHERE id = ? AND signup_status = 'pending'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user === false) {
            return false;
        }

        if ($user['email_verified_at'] !== null) {
            Mailer::queue('signup_declined', ['user' => $user, 'address' => (string) $user['email']]);
        }

        Database::connection()->prepare("DELETE FROM users WHERE id = ? AND signup_status = 'pending'")->execute([$userId]);

        return true;
    }

    /** Removes sign-ups whose address was never confirmed. */
    public static function purgeStale(): int
    {
        $stmt = Database::connection()->prepare(
            "DELETE FROM users WHERE signup_status = 'pending' AND email_verified_at IS NULL AND created_at < now() - make_interval(days => ?)"
        );
        $stmt->execute([self::STALE_DAYS]);

        return $stmt->rowCount();
    }

    /**
     * What to tell someone whose sign-in failed, if the username and
     * password are right but the account is still waiting: null otherwise.
     */
    public static function waitingMessage(string $username, #[\SensitiveParameter] string $password): ?string
    {
        $stmt = Database::connection()->prepare("SELECT password_hash, email_verified_at FROM users WHERE (username = ? OR email = ?) AND signup_status = 'pending' LIMIT 1");
        $stmt->execute([$username, $username]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, (string) $row['password_hash'])) {
            return null;
        }

        return __($row['email_verified_at'] === null ? 'ui.message.signup_confirm_first' : 'ui.message.signup_waiting');
    }
}
