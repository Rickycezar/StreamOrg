<?php
declare(strict_types=1);

/**
 * The system e-mails StreamOrg can send, and their texts.
 *
 * Each message (CODES) has the same fields (FIELDS): a subject, the
 * preview line inboxes show, a heading, the body, the button's label and
 * a small note under it. Their defaults live in the language files
 * (ui.email_msg.<code>_<field>); Administration → E-mails changes them per
 * language (email_template_overrides), and a change that drops or invents
 * a {placeholder} is refused, as in the game texts.
 *
 * Per message: the {placeholders} its texts may use, whether it has a
 * button, whether it goes only to confirmed addresses ("verified"),
 * whether the person can turn it off ("optional", the notification
 * copies), and its kind (account, security, notification, test), shown
 * as a small label above the heading.
 */
final class EmailTemplates
{
    public const FIELDS = ['subject', 'preheader', 'heading', 'body', 'button', 'note'];

    public const CODES = [
        'welcome'          => ['placeholders' => ['name', 'site', 'username', 'hours'], 'button' => true, 'verified' => false, 'optional' => false, 'kind' => 'account'],
        'verify_email'     => ['placeholders' => ['name', 'site', 'hours'], 'button' => true, 'verified' => false, 'optional' => false, 'kind' => 'account'],
        'email_changed'    => ['placeholders' => ['name', 'site', 'new_email'], 'button' => false, 'verified' => false, 'optional' => false, 'kind' => 'security'],
        'password_reset'   => ['placeholders' => ['name', 'site', 'minutes'], 'button' => true, 'verified' => false, 'optional' => false, 'kind' => 'security'],
        'password_changed' => ['placeholders' => ['name', 'site', 'time'], 'button' => true, 'verified' => false, 'optional' => false, 'kind' => 'security'],
        'signup_approved'  => ['placeholders' => ['name', 'site', 'username'], 'button' => true, 'verified' => false, 'optional' => false, 'kind' => 'account'],
        'signup_declined'  => ['placeholders' => ['name', 'site'], 'button' => false, 'verified' => false, 'optional' => false, 'kind' => 'account'],
        'notification'     => ['placeholders' => ['name', 'site', 'title', 'text'], 'button' => true, 'verified' => true, 'optional' => true, 'kind' => 'notification'],
        'test'             => ['placeholders' => ['name', 'site'], 'button' => true, 'verified' => false, 'optional' => false, 'kind' => 'test'],
    ];

    /** The longest a field may be, by field. */
    public const LIMITS = ['subject' => 150, 'preheader' => 150, 'heading' => 150, 'body' => 4000, 'button' => 60, 'note' => 600];

    public static function exists(string $code): bool
    {
        return isset(self::CODES[$code]);
    }

    /** @return array<string, string> a message's texts in a language: the defaults with the changes over them */
    public static function texts(string $code, string $locale): array
    {
        return array_replace(self::defaults($code, $locale), self::overrides($code, $locale));
    }

    /** @return array<string, string> a message's texts as they ship, field => text */
    public static function defaults(string $code, string $locale): array
    {
        $texts = [];

        foreach (self::FIELDS as $field) {
            $key  = 'ui.email_msg.' . $code . '_' . $field;
            $text = Lang::t($key, $locale);
            $texts[$field] = $text === $key ? '' : $text;
        }

        return $texts;
    }

    /** @return array<string, string> the fields changed in Administration → E-mails */
    public static function overrides(string $code, string $locale): array
    {
        try {
            $stmt = Database::connection()->prepare('SELECT field, value FROM email_template_overrides WHERE code = ? AND locale = ?');
            $stmt->execute([$code, $locale]);

            return array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> the {placeholders} in a text, sorted */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $found);
        $names = array_values(array_unique($found[1]));
        sort($names);

        return $names;
    }

    /**
     * Saves one message's texts in a language, as typed: blank or equal to
     * the default clears the change. A text that uses a {placeholder} the
     * message does not have, or that drops one its default uses, is
     * refused.
     *
     * @param array<string, string> $values field => text
     * @return array{saved:int, refused:list<string>} the fields refused
     */
    public static function save(string $code, string $locale, array $values, ?int $userId): array
    {
        if (!self::exists($code) || !in_array($locale, Lang::available(), true)) {
            throw new UserError(__('ui.message.invalid_input'));
        }

        $defaults = self::defaults($code, $locale);
        $allowed  = self::CODES[$code]['placeholders'];
        $pdo      = Database::connection();
        $put      = $pdo->prepare(
            'INSERT INTO email_template_overrides (code, locale, field, value, updated_by) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (code, locale, field) DO UPDATE SET value = excluded.value, updated_by = excluded.updated_by, updated_at = now()'
        );
        $clear    = $pdo->prepare('DELETE FROM email_template_overrides WHERE code = ? AND locale = ? AND field = ?');
        $saved    = 0;
        $refused  = [];

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }

            $value = str_replace("\r\n", "\n", trim((string) $values[$field]));

            if ($field !== 'body') {
                $value = trim((string) preg_replace('/\s+/', ' ', $value));
            }

            if ($value === '' || $value === $defaults[$field]) {
                $clear->execute([$code, $locale, $field]);
                continue;
            }

            $used = self::placeholders($value);

            if (mb_strlen($value) > self::LIMITS[$field] || array_diff($used, $allowed) !== []
                || array_diff(self::placeholders($defaults[$field]), $used) !== []) {
                $refused[] = $field;
                continue;
            }

            $put->execute([$code, $locale, $field, $value, $userId]);
            $saved++;
        }

        return ['saved' => $saved, 'refused' => $refused];
    }

    /** @return int how many fields are changed, in all messages and languages */
    public static function changedCount(): int
    {
        try {
            return (int) Database::connection()->query('SELECT count(*) FROM email_template_overrides')->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}
