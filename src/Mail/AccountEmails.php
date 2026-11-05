<?php
declare(strict_types=1);

/**
 * The e-mails an account gets, one method per moment: welcome (set your
 * password), confirm your address, your address changed (to the old one),
 * reset a forgotten password, your password changed, and copies of
 * notifications. Each builds its link (EmailTokens) and queues the
 * message (Mailer), which decides whether it goes at all.
 */
final class AccountEmails
{
    public const VERIFY_HOURS = 48;

    public const WELCOME_HOURS = 72;

    public const RESET_MINUTES = 30;

    /** A new account: a link to choose the password, which also confirms the address. */
    public static function welcome(array $user): ?int
    {
        if (!Mailer::on()) {
            return null;
        }

        $token = EmailTokens::issue((int) $user['id'], 'welcome', (string) $user['email'], self::WELCOME_HOURS * 60);

        return Mailer::queue('welcome', ['user' => $user], ['username' => (string) $user['username'], 'hours' => self::WELCOME_HOURS], self::link($user, '/password/reset?t=' . $token));
    }

    /** A link that confirms the account's address. */
    public static function verify(array $user): ?int
    {
        if (!Mailer::on()) {
            return null;
        }

        $token = EmailTokens::issue((int) $user['id'], 'verify', (string) $user['email'], self::VERIFY_HOURS * 60);

        return Mailer::queue('verify_email', ['user' => $user], ['hours' => self::VERIFY_HOURS], self::link($user, '/email/verify?t=' . $token));
    }

    /** The address changed: the old address is told (no link), the new one is asked to confirm. */
    public static function emailChanged(array $user, string $oldAddress): void
    {
        if (!Mailer::on()) {
            return;
        }

        Mailer::queue('email_changed', ['user' => $user, 'address' => $oldAddress], ['new_email' => self::masked((string) $user['email'])]);
        self::verify($user);
    }

    /** A forgotten password: a short-lived link to choose a new one. */
    public static function passwordReset(array $user): ?int
    {
        if (!Mailer::on()) {
            return null;
        }

        $token = EmailTokens::issue((int) $user['id'], 'reset', (string) $user['email'], self::RESET_MINUTES);

        return Mailer::queue('password_reset', ['user' => $user], ['minutes' => self::RESET_MINUTES], self::link($user, '/password/reset?t=' . $token));
    }

    /** The password changed (by the person, a reset link or an administrator). */
    public static function passwordChanged(array $user): ?int
    {
        if (!Mailer::on()) {
            return null;
        }

        $time = (new DateTimeImmutable('now', new DateTimeZone((string) ($user['timezone'] ?: 'UTC'))))->format('d/m/Y H:i T');

        return Mailer::queue('password_changed', ['user' => $user], ['time' => $time], self::link($user, '/profile/security'));
    }

    /**
     * Copies of a notification to its recipients who want them: the title
     * and text in each one's language, with its link.
     *
     * @param array<string, array{title:string, body:string}> $texts
     * @param ?list<int> $userIds null: everyone
     */
    public static function notification(array $texts, ?string $link, ?array $userIds): void
    {
        if (!Mailer::on() || $texts === []) {
            return;
        }

        $sql  = 'SELECT * FROM users WHERE is_active AND email_notifications AND email_verified_at IS NOT NULL';
        $stmt = Database::connection()->prepare($userIds === null ? $sql : $sql . ' AND id = ANY(CAST(? AS bigint[]))');
        $stmt->execute($userIds === null ? [] : ['{' . implode(',', array_map('intval', $userIds)) . '}']);

        foreach ($stmt->fetchAll() as $user) {
            $locale = isset($texts[$user['locale']]) ? (string) $user['locale'] : (isset($texts['en']) ? 'en' : (string) array_key_first($texts));
            $text   = $texts[$locale];

            Mailer::queue('notification', ['user' => $user, 'locale' => (string) $user['locale']], [
                'title' => (string) $text['title'],
                'text'  => NoteFormat::plain((string) $text['body']),
            ], self::link($user, $link !== null && str_starts_with($link, '/') ? $link : '/notifications'));
        }
    }

    /** An address shown half hidden ("ri••••@gmail.com"), for the e-mail to the old address; not "*", which e-mails read as bold. */
    public static function masked(string $address): string
    {
        [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');

        return mb_substr($local, 0, 2) . str_repeat('•', max(2, mb_strlen($local) - 2)) . '@' . $domain;
    }

    /** An absolute link on the account's language's domain. */
    private static function link(array $user, string $path): string
    {
        return rtrim(public_base_url((string) ($user['locale'] ?? null)), '/') . url($path);
    }
}
