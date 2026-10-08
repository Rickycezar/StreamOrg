<?php
declare(strict_types=1);

/**
 * Bug reports: a user tells what went wrong, administrators work on it,
 * and both talk on the report until it is fixed.
 *
 * The reporter sees their reports with where each one stands (new,
 * confirmed, being fixed, fixed…), the administrators' replies and every
 * change, and is told through a notification when something happens. They
 * can add details, answer a question, confirm a fix or say it still
 * happens (which opens it again). Administrators set the status and
 * priority, reply, keep internal notes, and mark a report fixed with a
 * message for the reporter. See 044_support.sql.
 */
final class BugReports
{
    public const STATUSES   = ['new', 'confirmed', 'in_progress', 'need_info', 'fixed', 'wont_fix', 'duplicate', 'closed'];
    public const OPEN       = ['new', 'confirmed', 'in_progress', 'need_info'];
    public const IMPACTS    = ['blocking', 'annoying', 'cosmetic'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const LINK       = '/support/bug/view?id=';

    /**
     * Reads the report form.
     *
     * @return array{title:string, page:?string, description:string, steps:?string, expected:?string, impact:string}
     * @throws UserError naming what is missing
     */
    public static function fromInput(array $in): array
    {
        $line = static fn (mixed $v, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $v)), 0, $max);
        $text = static fn (mixed $v, int $max): string => mb_substr(trim(str_replace("\r\n", "\n", (string) $v)), 0, $max);

        $title = $line($in['title'] ?? '', 140);
        $description = $text($in['description'] ?? '', 5000);

        if (mb_strlen($title) < 3) {
            throw new UserError(__('ui.message.bug_title_needed'));
        }

        if ($description === '') {
            throw new UserError(__('ui.message.bug_description_needed'));
        }

        $steps = array_values(array_filter(array_map(
            static fn (mixed $s): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $s)), 0, 500),
            (array) ($in['steps'] ?? [])
        ), static fn (string $s): bool => $s !== ''));

        $page = $line($in['page'] ?? '', 300);

        return [
            'title'       => $title,
            'page'        => $page !== '' ? $page : null,
            'description' => $description,
            'steps'       => $steps !== [] ? implode("\n", array_slice($steps, 0, 30)) : null,
            'expected'    => $text($in['expected'] ?? '', 2000) ?: null,
            'impact'      => in_array($in['impact'] ?? '', self::IMPACTS, true) ? (string) $in['impact'] : 'annoying',
        ];
    }

    /**
     * What the browser says about itself, for the administrators: kept
     * short and only from a known list of keys.
     *
     * @return array<string, string>
     */
    public static function environment(array $in, string $userAgent): array
    {
        $env = ['browser' => mb_substr($userAgent, 0, 300)];

        foreach (['screen', 'viewport', 'language', 'timezone', 'theme', 'platform', 'error_code', 'error_ref'] as $key) {
            $value = mb_substr(trim((string) ($in[$key] ?? '')), 0, 80);

            if ($value !== '') {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * Files a report with its screenshots and tells the administrators.
     *
     * @param array{title:string, page:?string, description:string, steps:?string, expected:?string, impact:string} $report
     * @param list<int> $imageIds
     */
    public static function create(int $userId, array $report, array $environment = [], array $imageIds = [], array $pins = []): int
    {
        $id = Database::transaction(static function (PDO $pdo) use ($userId, $report, $environment, $imageIds, $pins): int {
            $stmt = $pdo->prepare(
                'INSERT INTO bug_reports (user_id, title, page, description, steps, expected, impact, environment, seen_by_user_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), now()) RETURNING id'
            );
            $stmt->execute([
                $userId, $report['title'], $report['page'], $report['description'],
                $report['steps'], $report['expected'], $report['impact'], json_encode($environment ?: new stdClass()),
            ]);
            $id = (int) $stmt->fetchColumn();

            SupportImages::attach($pdo, $userId, $imageIds, $id, null, $pins);

            return $id;
        });

        Notifications::system(
            array_values(array_diff(Notifications::adminIds(), [$userId])),
            'bug_new',
            ['#' . $id, $report['title'], self::name($userId)],
            '/admin/bugs/view?id=' . $id,
            $report['impact'] === 'blocking' ? 'warning' : 'info'
        );

        return $id;
    }

    /**
     * A report, for its reporter or an administrator; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $bugId, int $userId, bool $isAdmin): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT b.*, coalesce(u.display_name, u.username) AS reporter, u.username AS reporter_username, u.email AS reporter_email
               FROM bug_reports b LEFT JOIN users u ON u.id = b.user_id
              WHERE b.id = ? AND (CAST(? AS boolean) OR b.user_id = ?)'
        );
        $stmt->execute([$bugId, $isAdmin ? 'true' : 'false', $userId]);
        $bug = $stmt->fetch();

        if ($bug === false) {
            return null;
        }

        $bug['environment'] = json_decode((string) $bug['environment'], true) ?: [];
        $bug['steps_list']  = $bug['steps'] !== null ? explode("\n", (string) $bug['steps']) : [];

        return $bug;
    }

    /**
     * The conversation and history of a report, oldest first (internal
     * notes only for administrators).
     *
     * @return list<array<string, mixed>>
     */
    public static function timeline(int $bugId, bool $withInternal): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, coalesce(u.display_name, u.username) AS author, u.role AS author_role, u.avatar_path, u.avatar_updated_at,
                    u.display_name, u.username
               FROM bug_comments c LEFT JOIN users u ON u.id = c.author_id
              WHERE c.bug_id = ? AND (CAST(? AS boolean) OR NOT c.is_internal)
           ORDER BY c.created_at, c.id'
        );
        $stmt->execute([$bugId, $withInternal ? 'true' : 'false']);

        return $stmt->fetchAll();
    }

    /**
     * The reporter adds to their report: more details, an answer, screenshots.
     * A report waiting for information goes back to the administrators.
     *
     * @throws UserError when there is nothing to send
     */
    public static function userComment(int $userId, int $bugId, string $body, array $imageIds = [], array $pins = []): void
    {
        $bug = self::find($bugId, $userId, false);

        if ($bug === null) {
            throw new UserError(__('ui.message.bug_not_found'));
        }

        $body = mb_substr(trim(str_replace("\r\n", "\n", $body)), 0, 5000);

        if ($body === '' && $imageIds === []) {
            throw new UserError(__('ui.message.bug_comment_empty'));
        }

        $reopen = $bug['status'] === 'need_info' ? 'confirmed' : null;

        Database::transaction(static function (PDO $pdo) use ($userId, $bugId, $body, $imageIds, $pins, $reopen, $bug): void {
            $stmt = $pdo->prepare(
                'INSERT INTO bug_comments (bug_id, author_id, body, from_status, to_status) VALUES (?, ?, ?, ?, ?) RETURNING id'
            );
            $stmt->execute([$bugId, $userId, $body !== '' ? $body : null, $reopen ? $bug['status'] : null, $reopen]);
            SupportImages::attach($pdo, $userId, $imageIds, $bugId, (int) $stmt->fetchColumn(), $pins);

            $pdo->prepare(
                'UPDATE bug_reports SET updated_at = now(), seen_by_admin_at = NULL, seen_by_user_at = now(), status = coalesce(?, status) WHERE id = ?'
            )->execute([$reopen, $bugId]);
        });

        Notifications::system(
            array_values(array_diff(Notifications::adminIds(), [$userId])),
            'bug_user_reply',
            ['#' . $bugId, (string) $bug['title'], self::name($userId)],
            '/admin/bugs/view?id=' . $bugId
        );
    }

    /**
     * The reporter answers a fix: it works (the report is closed) or it
     * still happens (it opens again, with what they say).
     *
     * @throws UserError when the report is not waiting for that answer
     */
    public static function answerFix(int $userId, int $bugId, bool $works, string $body = ''): void
    {
        $bug = self::find($bugId, $userId, false);

        if ($bug === null || $bug['status'] !== 'fixed') {
            throw new UserError(__('ui.message.bug_not_fixed_yet'));
        }

        $to   = $works ? 'closed' : 'confirmed';
        $body = mb_substr(trim($body), 0, 5000);

        Database::transaction(static function (PDO $pdo) use ($userId, $bugId, $to, $body): void {
            $pdo->prepare('INSERT INTO bug_comments (bug_id, author_id, body, from_status, to_status) VALUES (?, ?, ?, ?, ?)')
                ->execute([$bugId, $userId, $body !== '' ? $body : null, 'fixed', $to]);
            $pdo->prepare('UPDATE bug_reports SET status = ?, updated_at = now(), seen_by_admin_at = NULL, seen_by_user_at = now() WHERE id = ?')
                ->execute([$to, $bugId]);
        });

        Notifications::system(
            array_values(array_diff(Notifications::adminIds(), [$userId])),
            $works ? 'bug_fix_confirmed' : 'bug_still_happens',
            ['#' . $bugId, (string) $bug['title'], self::name($userId)],
            '/admin/bugs/view?id=' . $bugId,
            $works ? 'success' : 'warning'
        );
    }

    /**
     * An administrator works on a report: a reply (to the reporter, or an
     * internal note), a new status, a new priority, any of them at once.
     * The reporter is told when they get a reply or the status changes.
     *
     * @throws UserError when nothing would change
     */
    public static function adminUpdate(int $adminId, int $bugId, array $in): void
    {
        $bug = self::find($bugId, $adminId, true);

        if ($bug === null) {
            throw new UserError(__('ui.message.bug_not_found'));
        }

        $body     = mb_substr(trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? ''))), 0, 5000);
        $internal = !empty($in['internal']);
        $status   = in_array($in['status'] ?? '', self::STATUSES, true) ? (string) $in['status'] : $bug['status'];
        $priority = in_array($in['priority'] ?? '', self::PRIORITIES, true) ? (string) $in['priority'] : $bug['priority'];
        $dupOf    = $status === 'duplicate' ? (filter_var($in['duplicate_of'] ?? null, FILTER_VALIDATE_INT) ?: null) : null;
        $changed  = $status !== $bug['status'];
        $images   = array_map('intval', (array) ($in['images'] ?? []));

        if ($body === '' && !$changed && $priority === $bug['priority'] && $images === []) {
            throw new UserError(__('ui.message.bug_nothing_to_update'));
        }

        if ($dupOf !== null && $dupOf === $bugId) {
            $dupOf = null;
        }

        Database::transaction(static function (PDO $pdo) use ($adminId, $bugId, $bug, $body, $internal, $status, $priority, $dupOf, $changed, $images, $in): void {
            if ($body !== '' || $changed || $images !== []) {
                $stmt = $pdo->prepare(
                    'INSERT INTO bug_comments (bug_id, author_id, body, is_internal, from_status, to_status) VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
                );
                $stmt->execute([
                    $bugId, $adminId, $body !== '' ? $body : null, $internal && !$changed ? 'true' : 'false',
                    $changed ? $bug['status'] : null, $changed ? $status : null,
                ]);
                SupportImages::attach($pdo, $adminId, $images, $bugId, (int) $stmt->fetchColumn(), (array) ($in['pins'] ?? []));
            }

            $pdo->prepare(
                'UPDATE bug_reports SET status = ?, priority = ?, duplicate_of = ?, updated_at = now(), seen_by_admin_at = now(),
                        fixed_at = CASE WHEN ? = \'fixed\' AND status <> \'fixed\' THEN now() WHEN ? = \'fixed\' THEN fixed_at ELSE NULL END,
                        seen_by_user_at = CASE WHEN CAST(? AS boolean) THEN NULL ELSE seen_by_user_at END
                  WHERE id = ?'
            )->execute([$status, $priority, $dupOf, $status, $status, ($changed || ($body !== '' && !$internal)) ? 'true' : 'false', $bugId]);
        });

        if ($bug['user_id'] === null || (int) $bug['user_id'] === $adminId || (!$changed && ($body === '' || $internal))) {
            return;
        }

        Notifications::system(
            [(int) $bug['user_id']],
            $changed ? 'bug_status_' . $status : 'bug_admin_reply',
            ['#' . $bugId, (string) $bug['title']],
            self::LINK . $bugId,
            $changed && $status === 'fixed' ? 'success' : 'info'
        );
    }

    /**
     * A user's reports, newest change first, with whether something new
     * happened since they last looked.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT b.id, b.title, b.status, b.impact, b.page, b.created_at, b.updated_at,
                    (b.seen_by_user_at IS NULL OR b.seen_by_user_at < b.updated_at) AS unread,
                    (SELECT count(*) FROM bug_comments c WHERE c.bug_id = b.id AND NOT c.is_internal AND c.body IS NOT NULL) AS replies
               FROM bug_reports b
              WHERE b.user_id = ?
           ORDER BY (b.status = ANY('{new,confirmed,in_progress,need_info,fixed}')) DESC, b.updated_at DESC"
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /** Marks a report seen by its reporter, or by the administrators. */
    public static function markSeen(int $bugId, bool $byAdmin): void
    {
        Database::connection()->prepare(
            'UPDATE bug_reports SET ' . ($byAdmin ? 'seen_by_admin_at' : 'seen_by_user_at') . ' = now() WHERE id = ?'
        )->execute([$bugId]);
    }

    /**
     * Reports for the administrators' tracker.
     *
     * @return list<array<string, mixed>>
     */
    public static function adminList(string $show = 'open', string $priority = '', string $impact = '', string $query = ''): array
    {
        $where  = [];
        $params = [];

        $where[] = match ($show) {
            'open'   => "b.status IN ('new', 'confirmed', 'in_progress', 'need_info')",
            'all'    => 'true',
            default  => in_array($show, self::STATUSES, true) ? 'b.status = :status' : 'true',
        };

        if (in_array($show, self::STATUSES, true)) {
            $params['status'] = $show;
        }

        if (in_array($priority, self::PRIORITIES, true)) {
            $where[] = 'b.priority = :priority';
            $params['priority'] = $priority;
        }

        if (in_array($impact, self::IMPACTS, true)) {
            $where[] = 'b.impact = :impact';
            $params['impact'] = $impact;
        }

        if (trim($query) !== '') {
            $where[] = '(b.title ILIKE :q OR b.description ILIKE :q OR b.page ILIKE :q OR u.username ILIKE :q OR u.display_name ILIKE :q OR CAST(b.id AS text) = :exact)';
            $params['q']     = '%' . strtr(trim($query), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            $params['exact'] = ltrim(trim($query), '#');
        }

        $stmt = Database::connection()->prepare(
            "SELECT b.id, b.title, b.page, b.status, b.priority, b.impact, b.created_at, b.updated_at,
                    coalesce(u.display_name, u.username) AS reporter,
                    (b.seen_by_admin_at IS NULL OR b.seen_by_admin_at < b.updated_at) AS unread,
                    (SELECT count(*) FROM support_images i WHERE i.bug_id = b.id) AS images,
                    (SELECT count(*) FROM bug_comments c WHERE c.bug_id = b.id AND c.body IS NOT NULL) AS comments
               FROM bug_reports b LEFT JOIN users u ON u.id = b.user_id
              WHERE " . implode(' AND ', $where) . "
           ORDER BY array_position(ARRAY['urgent', 'high', 'normal', 'low'], b.priority),
                    array_position(ARRAY['blocking', 'annoying', 'cosmetic'], b.impact), b.updated_at DESC
              LIMIT 300"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * How many reports there are in each status, and how many have news
     * for the administrators.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $rows = Database::connection()->query(
            "SELECT status, count(*) FROM bug_reports GROUP BY status
              UNION ALL
             SELECT 'unread', count(*) FROM bug_reports WHERE seen_by_admin_at IS NULL OR seen_by_admin_at < updated_at"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_map('intval', $rows) + array_fill_keys([...self::STATUSES, 'unread'], 0);
    }

    private static function name(int $userId): string
    {
        $stmt = Database::connection()->prepare('SELECT coalesce(display_name, username) FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) ($stmt->fetchColumn() ?: '?');
    }
}
