<?php
declare(strict_types=1);

/**
 * Feedback messages: a user writes to the administrators (an idea, praise,
 * a problem, a question) and the conversation continues as replies, like
 * an email thread. Administrators read them in an inbox where threads can
 * be starred, archived or marked unread; each side is told through a
 * notification when the other replies. See 044_support.sql.
 */
final class Feedback
{
    public const CATEGORIES = ['idea', 'praise', 'problem', 'question', 'other'];
    public const LINK = '/support/message?id=';

    /**
     * Starts a thread and tells the administrators.
     *
     * @throws UserError when the subject or the message is missing
     */
    public static function create(int $userId, string $subject, string $category, string $body): int
    {
        $subject = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $subject)), 0, 140);
        $body    = self::body($body);

        if ($subject === '') {
            throw new UserError(__('ui.message.feedback_subject_needed'));
        }

        $category = in_array($category, self::CATEGORIES, true) ? $category : 'other';

        $id = Database::transaction(static function (PDO $pdo) use ($userId, $subject, $category, $body): int {
            $stmt = $pdo->prepare('INSERT INTO feedback_threads (user_id, subject, category) VALUES (?, ?, ?) RETURNING id');
            $stmt->execute([$userId, $subject, $category]);
            $id = (int) $stmt->fetchColumn();

            $pdo->prepare('INSERT INTO feedback_messages (thread_id, author_id, body) VALUES (?, ?, ?)')->execute([$id, $userId, $body]);

            return $id;
        });

        Notifications::system(
            array_values(array_diff(Notifications::adminIds(), [$userId])),
            'feedback_new',
            [self::name($userId), $subject],
            '/admin/feedback?id=' . $id
        );

        return $id;
    }

    /**
     * A reply in a thread, from its author or an administrator; the other
     * side is told. A reply brings an archived thread back to the inbox.
     *
     * @throws UserError when the thread is not theirs, or the reply is empty
     */
    public static function reply(int $authorId, int $threadId, string $body, bool $asAdmin): void
    {
        $thread = self::thread($threadId, $authorId, $asAdmin);

        if ($thread === null) {
            throw new UserError(__('ui.message.feedback_not_found'));
        }

        $body = self::body($body);

        Database::transaction(static function (PDO $pdo) use ($authorId, $threadId, $body, $asAdmin): void {
            $pdo->prepare('INSERT INTO feedback_messages (thread_id, author_id, from_admin, body) VALUES (?, ?, ?, ?)')
                ->execute([$threadId, $authorId, $asAdmin ? 'true' : 'false', $body]);
            $pdo->prepare(
                'UPDATE feedback_threads SET last_message_at = now(), is_archived = false, '
                . ($asAdmin ? 'user_unread = true, admin_unread = false' : 'admin_unread = true, user_unread = false')
                . ' WHERE id = ?'
            )->execute([$threadId]);
        });

        if ($asAdmin) {
            if ($thread['user_id'] !== null && (int) $thread['user_id'] !== $authorId) {
                Notifications::system([(int) $thread['user_id']], 'feedback_reply', [(string) $thread['subject']], self::LINK . $threadId);
            }

            return;
        }

        Notifications::system(
            array_values(array_diff(Notifications::adminIds(), [$authorId])),
            'feedback_user_reply',
            [self::name($authorId), (string) $thread['subject']],
            '/admin/feedback?id=' . $threadId
        );
    }

    /**
     * A thread with its messages, for its author or an administrator.
     *
     * @return array<string, mixed>|null
     */
    public static function thread(int $threadId, int $userId, bool $isAdmin): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.*, coalesce(u.display_name, u.username) AS author, u.username, u.email, u.avatar_path, u.avatar_updated_at, u.display_name
               FROM feedback_threads t LEFT JOIN users u ON u.id = t.user_id
              WHERE t.id = ? AND (CAST(? AS boolean) OR t.user_id = ?)'
        );
        $stmt->execute([$threadId, $isAdmin ? 'true' : 'false', $userId]);
        $thread = $stmt->fetch();

        if ($thread === false) {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT m.*, coalesce(u.display_name, u.username) AS author, u.avatar_path, u.avatar_updated_at, u.display_name, u.username
               FROM feedback_messages m LEFT JOIN users u ON u.id = m.author_id
              WHERE m.thread_id = ? ORDER BY m.created_at, m.id'
        );
        $stmt->execute([$threadId]);
        $thread['messages'] = $stmt->fetchAll();

        return $thread;
    }

    /** Marks a thread read by its author, or by the administrators. */
    public static function markRead(int $threadId, bool $byAdmin): void
    {
        Database::connection()->prepare(
            'UPDATE feedback_threads SET ' . ($byAdmin ? 'admin_unread' : 'user_unread') . ' = false WHERE id = ?'
        )->execute([$threadId]);
    }

    /**
     * A user's threads, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.id, t.subject, t.category, t.user_unread AS unread, t.created_at, t.last_message_at,
                    (SELECT count(*) FROM feedback_messages m WHERE m.thread_id = t.id) AS messages,
                    (SELECT bool_or(m.from_admin) FROM feedback_messages m WHERE m.thread_id = t.id) AS answered
               FROM feedback_threads t WHERE t.user_id = ? ORDER BY t.last_message_at DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * The administrators' inbox: inbox, starred, unread or archived threads,
     * optionally one category or matching a search, newest first, each with
     * the start of its last message.
     *
     * @return list<array<string, mixed>>
     */
    public static function inbox(string $box = 'inbox', string $category = '', string $query = ''): array
    {
        $where = [match ($box) {
            'starred'  => 't.is_starred',
            'unread'   => 't.admin_unread',
            'archived' => 't.is_archived',
            default    => 'NOT t.is_archived',
        }];
        $params = [];

        if (in_array($category, self::CATEGORIES, true)) {
            $where[] = 't.category = :category';
            $params['category'] = $category;
        }

        if (trim($query) !== '') {
            $where[] = '(t.subject ILIKE :q OR u.username ILIKE :q OR u.display_name ILIKE :q
                         OR EXISTS (SELECT 1 FROM feedback_messages m WHERE m.thread_id = t.id AND m.body ILIKE :q))';
            $params['q'] = '%' . strtr(trim($query), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        }

        $stmt = Database::connection()->prepare(
            'SELECT t.*, coalesce(u.display_name, u.username) AS author, u.avatar_path, u.avatar_updated_at, u.display_name, u.username,
                    (SELECT m.body FROM feedback_messages m WHERE m.thread_id = t.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_body,
                    (SELECT m.from_admin FROM feedback_messages m WHERE m.thread_id = t.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_from_admin,
                    (SELECT count(*) FROM feedback_messages m WHERE m.thread_id = t.id) AS messages
               FROM feedback_threads t LEFT JOIN users u ON u.id = t.user_id
              WHERE ' . implode(' AND ', $where) . '
           ORDER BY t.last_message_at DESC LIMIT 300'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * How many threads are in each box.
     *
     * @return array{inbox:int, unread:int, starred:int, archived:int}
     */
    public static function boxCounts(): array
    {
        $row = Database::connection()->query(
            'SELECT count(*) FILTER (WHERE NOT is_archived) AS inbox, count(*) FILTER (WHERE admin_unread) AS unread,
                    count(*) FILTER (WHERE is_starred) AS starred, count(*) FILTER (WHERE is_archived) AS archived
               FROM feedback_threads'
        )->fetch();

        return array_map('intval', $row);
    }

    /** Stars, archives or marks unread a thread (administrators). */
    public static function flag(int $threadId, string $flag, bool $on): void
    {
        $column = ['star' => 'is_starred', 'archive' => 'is_archived', 'unread' => 'admin_unread'][$flag] ?? null;

        if ($column === null) {
            return;
        }

        Database::connection()->prepare("UPDATE feedback_threads SET {$column} = ? WHERE id = ?")
            ->execute([$on ? 'true' : 'false', $threadId]);
    }

    /** @throws UserError */
    private static function body(string $body): string
    {
        $body = mb_substr(trim(str_replace("\r\n", "\n", $body)), 0, 5000);

        if ($body === '') {
            throw new UserError(__('ui.message.feedback_message_needed'));
        }

        return $body;
    }

    private static function name(int $userId): string
    {
        $stmt = Database::connection()->prepare('SELECT coalesce(display_name, username) FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) ($stmt->fetchColumn() ?: '?');
    }
}
