<?php
declare(strict_types=1);

/**
 * Notifications from the administration to everyone or to chosen users
 * (see 035_notifications.sql): the bell in the top bar, the notifications
 * page, and the admin's composer and history.
 *
 * Each notification is written in the app's languages; a user reads it in
 * their own, else in English, else in whichever was written. One sent
 * before a user's account existed is listed for them but never unread.
 */
final class Notifications
{
    public const LEVELS = ['info', 'news', 'success', 'warning'];

    public const TITLE_MAX = 120;
    public const BODY_MAX  = 2000;

    /** Notifications shown in the bell's panel. */
    public const PANEL = 8;

    /** Notifications per page on the notifications page. */
    public const PAGE = 30;

    private const FALLBACK = 'en';

    /**
     * Reads the composer: per-language title and body, kind, link and audience.
     *
     * @return array{texts: array<string, array{title:string, body:string}>, level:string, link:?string, users:?list<int>}
     *         users null means everyone
     * @throws UserError naming what is wrong
     */
    public static function fromInput(array $input): array
    {
        $texts = [];

        foreach (Lang::available() as $locale) {
            $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['title'][$locale] ?? '')));
            $body  = trim(str_replace("\r\n", "\n", (string) ($input['body'][$locale] ?? '')));

            if ($title === '' && $body === '') {
                continue;
            }

            if ($title === '' || mb_strlen($title) > self::TITLE_MAX || mb_strlen($body) > self::BODY_MAX) {
                throw new UserError(sprintf(__('ui.message.notification_text_invalid'), Lang::t('ui.label.language_name', $locale), self::TITLE_MAX, self::BODY_MAX));
            }

            $texts[$locale] = ['title' => $title, 'body' => $body];
        }

        if ($texts === []) {
            throw new UserError(__('ui.message.notification_no_text'));
        }

        $level = (string) ($input['level'] ?? 'info');
        $raw   = trim((string) ($input['link'] ?? ''));
        $link  = $raw === '' ? null : (str_starts_with($raw, '/') && !str_starts_with($raw, '//') ? $raw : safe_url($raw));

        if (!in_array($level, self::LEVELS, true) || ($raw !== '' && $link === null)) {
            throw new UserError(__('ui.message.invalid_input'));
        }

        $users = null;

        if (($input['audience'] ?? 'everyone') === 'users') {
            $users = array_values(array_unique(array_filter(array_map('intval', (array) ($input['users'] ?? [])))));

            if ($users === []) {
                throw new UserError(__('ui.message.notification_no_users'));
            }
        }

        return ['texts' => $texts, 'level' => $level, 'link' => $link, 'users' => $users];
    }

    /**
     * @param array{texts: array<string, array{title:string, body:string}>, level:string, link:?string, users:?list<int>} $notification
     * @return int the new notification's id
     */
    public static function send(array $notification, ?int $createdBy): int
    {
        return Database::transaction(static function (PDO $pdo) use ($notification, $createdBy): int {
            $stmt = $pdo->prepare(
                'INSERT INTO notifications (audience, level, link_url, created_by) VALUES (?, ?, ?, ?) RETURNING id'
            );
            $stmt->execute([$notification['users'] === null ? 'everyone' : 'users', $notification['level'], $notification['link'], $createdBy]);
            $id = (int) $stmt->fetchColumn();

            $text = $pdo->prepare('INSERT INTO notification_texts (notification_id, locale, title, body) VALUES (?, ?, ?, ?)');

            foreach ($notification['texts'] as $locale => $t) {
                $text->execute([$id, $locale, $t['title'], $t['body']]);
            }

            if ($notification['users'] !== null) {
                $pdo->prepare(
                    'INSERT INTO notification_recipients (notification_id, user_id)
                     SELECT ?, id FROM users WHERE id = ANY(CAST(? AS bigint[]))'
                )->execute([$id, '{' . implode(',', $notification['users']) . '}']);
            }

            return $id;
        });
    }

    /** The SQL that picks a user's notifications, with the text in their language. */
    private static function visibleSql(): string
    {
        return "SELECT n.id, n.level, n.link_url, n.created_at, t.title, t.body,
                       (r.read_at IS NULL AND n.created_at >= u.created_at) AS unread
                  FROM notifications n
                  JOIN users u ON u.id = :user
             LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :user
                  JOIN LATERAL (
                       SELECT title, body FROM notification_texts x
                        WHERE x.notification_id = n.id
                     ORDER BY (x.locale = :locale) DESC, (x.locale = :fallback) DESC, x.locale
                        LIMIT 1
                  ) t ON true
                 WHERE (n.audience = 'everyone'
                        OR EXISTS (SELECT 1 FROM notification_recipients p WHERE p.notification_id = n.id AND p.user_id = :user))";
    }

    /**
     * A user's notifications, newest first; with $before, the ones older than that id.
     *
     * @return list<array{id:int, level:string, link_url:?string, created_at:string, title:string, body:string, unread:bool}>
     */
    public static function forUser(int $userId, int $limit = self::PAGE, ?int $before = null): array
    {
        $stmt = Database::connection()->prepare(
            self::visibleSql()
            . ($before !== null ? ' AND n.id < :before' : '')
            . ' ORDER BY n.created_at DESC, n.id DESC LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute(['user' => $userId, 'locale' => Lang::locale(), 'fallback' => self::FALLBACK] + ($before !== null ? ['before' => $before] : []));

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'unread' => (bool) $row['unread']] + $row, $stmt->fetchAll());
    }

    public static function unreadCount(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT count(*)
               FROM notifications n
               JOIN users u ON u.id = :user
              WHERE n.created_at >= u.created_at
                AND NOT EXISTS (SELECT 1 FROM notification_reads r WHERE r.notification_id = n.id AND r.user_id = :user)
                AND (n.audience = 'everyone'
                     OR EXISTS (SELECT 1 FROM notification_recipients p WHERE p.notification_id = n.id AND p.user_id = :user))"
        );
        $stmt->execute(['user' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Marks one notification read (when it is visible to the user) and
     * returns it, or null when it is not theirs.
     */
    public static function open(int $userId, int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::visibleSql() . ' AND n.id = :id');
        $stmt->execute(['user' => $userId, 'locale' => Lang::locale(), 'fallback' => self::FALLBACK, 'id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        Database::connection()->prepare(
            'INSERT INTO notification_reads (notification_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING'
        )->execute([$id, $userId]);

        return $row;
    }

    /** Marks these notifications read (they were just shown to the user). */
    public static function markRead(int $userId, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        Database::connection()->prepare(
            'INSERT INTO notification_reads (notification_id, user_id)
             SELECT id, ? FROM unnest(CAST(? AS bigint[])) AS id
             ON CONFLICT DO NOTHING'
        )->execute([$userId, '{' . implode(',', $ids) . '}']);
    }

    /** Marks every notification the user can see as read. */
    public static function markAllRead(int $userId): void
    {
        Database::connection()->prepare(
            "INSERT INTO notification_reads (notification_id, user_id)
             SELECT n.id, :user FROM notifications n
              WHERE n.audience = 'everyone'
                 OR EXISTS (SELECT 1 FROM notification_recipients p WHERE p.notification_id = n.id AND p.user_id = :user)
             ON CONFLICT DO NOTHING"
        )->execute(['user' => $userId]);
    }

    /**
     * What the administration sent, newest first, with every language's
     * text, who it was for and how many have read it.
     *
     * @return list<array<string,mixed>>
     */
    public static function sent(int $limit = 50): array
    {
        $rows = Database::connection()->query(
            "SELECT n.id, n.audience, n.level, n.link_url, n.created_at, a.username AS author,
                    (SELECT count(*) FROM notification_reads r WHERE r.notification_id = n.id) AS reads,
                    CASE WHEN n.audience = 'everyone'
                         THEN (SELECT count(*) FROM users WHERE is_active AND created_at <= n.created_at)
                         ELSE (SELECT count(*) FROM notification_recipients p WHERE p.notification_id = n.id) END AS audience_size,
                    (SELECT string_agg(coalesce(u.display_name, u.username), ', ' ORDER BY u.username)
                       FROM notification_recipients p JOIN users u ON u.id = p.user_id
                      WHERE p.notification_id = n.id) AS recipients,
                    (SELECT json_object_agg(t.locale, json_build_object('title', t.title, 'body', t.body))
                       FROM notification_texts t WHERE t.notification_id = n.id) AS texts
               FROM notifications n
          LEFT JOIN users a ON a.id = n.created_by
           ORDER BY n.created_at DESC, n.id DESC
              LIMIT " . max(1, min(200, $limit))
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['texts'] = json_decode((string) $row['texts'], true) ?: [];
        }

        return $rows;
    }

    /** A text in the given language, else English, else any. */
    public static function textIn(array $texts, string $locale): array
    {
        return $texts[$locale] ?? $texts[self::FALLBACK] ?? (reset($texts) ?: ['title' => '', 'body' => '']);
    }

    /** Takes a notification back: it disappears for everyone. */
    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM notifications WHERE id = ?')->execute([$id]);
    }
}
