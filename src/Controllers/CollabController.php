<?php
declare(strict_types=1);

/**
 * Collabs: the planning record for working with other streamers.
 *
 * A collab exists before any stream does — an idea, then a proposal, then
 * an agreement. Once it is settled it produces a planned stream carrying
 * the same cast, which is what plan() does. The stream keeps collab_id so
 * the two stay linked.
 */
final class CollabController
{
    private const STATUSES = ['idea', 'proposed', 'agreed', 'scheduled', 'done', 'cancelled'];
    private const ROLES    = ['host', 'co_host', 'guest', 'raid'];

    public static function index(): void
    {
        Auth::requireLogin();

        $pdo    = Database::connection();
        $userId = Auth::id();

        $filters = ['status' => (string) ($_GET['status'] ?? '')];

        $sql = "SELECT c.*, sp.code AS platform_code,
                       count(cs.streamer_id)                       AS streamer_count,
                       string_agg(st.name, ', ' ORDER BY st.name)  AS streamers,
                       (SELECT count(*) FROM streams s WHERE s.collab_id = c.id) AS stream_count
                  FROM collabs c
             LEFT JOIN streaming_platforms sp ON sp.id = c.streaming_platform_id
             LEFT JOIN collab_streamers cs    ON cs.collab_id = c.id
             LEFT JOIN streamers st           ON st.id = cs.streamer_id
                 WHERE c.user_id = :user";

        $params = ['user' => $userId];

        if (in_array($filters['status'], self::STATUSES, true)) {
            $sql .= ' AND c.status = :status';
            $params['status'] = $filters['status'];
        }

        $sql .= ' GROUP BY c.id, sp.code ORDER BY c.proposed_at NULLS LAST, c.proposed_for NULLS LAST, c.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $collabs = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT status, count(*) AS total FROM collabs WHERE user_id = ? GROUP BY status');
        $stmt->execute([$userId]);
        $counts = array_column($stmt->fetchAll(), 'total', 'status');

        $stmt = $pdo->prepare(
            'SELECT cs.collab_id, cs.streamer_id FROM collab_streamers cs
               JOIN collabs c ON c.id = cs.collab_id WHERE c.user_id = ?'
        );
        $stmt->execute([$userId]);

        $cast = [];

        foreach ($stmt->fetchAll() as $row) {
            $cast[(int) $row['collab_id']][] = (int) $row['streamer_id'];
        }

        $stmt = $pdo->prepare('SELECT id, name FROM streamers WHERE user_id = ? ORDER BY is_favorite DESC, name');
        $stmt->execute([$userId]);

        View::render('collabs/index', [
            'collabs'   => $collabs,
            'counts'    => $counts,
            'cast'      => $cast,
            'filters'   => $filters,
            'statuses'  => self::STATUSES,
            'roles'     => self::ROLES,
            'streamers' => $stmt->fetchAll(),
            'platforms' => $pdo->query('SELECT code FROM streaming_platforms WHERE is_enabled ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN),
        ], __('ui.nav.collabs'));
    }

    public static function store(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $title = trim((string) ($_POST['title'] ?? ''));

        if ($title === '') {
            flash('error', __('ui.message.invalid_input'));
            redirect('/collabs');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO collabs (user_id, title, status, streaming_platform_id,
                                      proposed_at, proposed_for, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id'
            );
            $stmt->execute([
                Auth::id(),
                $title,
                in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : 'idea',
                self::platformId($pdo, (string) ($_POST['platform'] ?? '')),
                trim((string) ($_POST['proposed_at'] ?? '')) ?: null,
                trim((string) ($_POST['proposed_at'] ?? '')) ? substr((string) $_POST['proposed_at'], 0, 10) : null,
                trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            self::syncCast($pdo, (int) $stmt->fetchColumn(), $_POST['streamers'] ?? []);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('StreamOrg collab: ' . $e->getMessage());
            flash('error', __('ui.message.server_error'));
            redirect('/collabs');
        }

        flash('success', __('ui.message.saved'));
        redirect('/collabs');
    }

    /** AJAX: inline edit, cast included. */
    public static function update(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id    = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $title = trim((string) ($_POST['title'] ?? ''));

        if ($id === false || $id === null || $title === '') {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'UPDATE collabs
                SET title = :title, status = :status, streaming_platform_id = :platform,
                    proposed_at = :at, proposed_for = :for, notes = :notes
              WHERE id = :id AND user_id = :user'
        );

        $at = trim((string) ($_POST['proposed_at'] ?? '')) ?: null;

        $stmt->execute([
            'title'    => $title,
            'status'   => in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : 'idea',
            'platform' => self::platformId($pdo, (string) ($_POST['platform'] ?? '')),
            'at'       => $at,
            'for'      => $at !== null ? substr($at, 0, 10) : null,
            'notes'    => trim((string) ($_POST['notes'] ?? '')) ?: null,
            'id'       => $id,
            'user'     => Auth::id(),
        ]);

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        if (array_key_exists('streamers', $_POST)) {
            self::syncCast($pdo, $id, $_POST['streamers']);
        }

        json_response(['ok' => true, 'message' => __('ui.message.saved')]);
    }

    public static function delete(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $stmt = Database::connection()->prepare('DELETE FROM collabs WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, Auth::id()]);

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        json_response(['ok' => true, 'message' => __('ui.message.deleted')]);
    }

    /**
     * AJAX: turn a collab into a planned stream.
     *
     * The cast is copied into stream_collaborators, which is what makes the
     * stream count as a collab everywhere else in the app. A collab with
     * nobody on it is refused — that is just a stream.
     */
    public static function plan(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT c.*, sp.code AS platform_code,
                    (SELECT count(*) FROM collab_streamers cs WHERE cs.collab_id = c.id) AS cast_size
               FROM collabs c
          LEFT JOIN streaming_platforms sp ON sp.id = c.streaming_platform_id
              WHERE c.id = ? AND c.user_id = ?'
        );
        $stmt->execute([$id, Auth::id()]);
        $collab = $stmt->fetch();

        if ($collab === false) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        if ((int) $collab['cast_size'] === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.collab_needs_streamers')], 400);
        }

        $platformId = $collab['streaming_platform_id']
            ?? self::platformId($pdo, (string) ($_POST['platform'] ?? ''))
            ?? Auth::user()['channel_platform_id']
            ?? $pdo->query("SELECT id FROM streaming_platforms WHERE code = 'twitch'")->fetchColumn();

        if (!$platformId) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $scheduled = trim((string) ($_POST['scheduled_start'] ?? '')) ?: $collab['proposed_at'];

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO streams (user_id, streaming_platform_id, collab_id, title,
                                      status, scheduled_start, deadline)
                 VALUES (?, ?, ?, ?, 'planned', ?, ?) RETURNING id"
            );
            $stmt->execute([
                Auth::id(),
                $platformId,
                $id,
                $collab['title'],
                $scheduled,
                (new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:sP'),
            ]);

            $streamId = (int) $stmt->fetchColumn();

            $pdo->prepare(
                'INSERT INTO stream_collaborators (stream_id, streamer_id, role, confirmation, note)
                 SELECT ?, cs.streamer_id, cs.role, cs.confirmation, cs.note
                   FROM collab_streamers cs WHERE cs.collab_id = ?
                 ON CONFLICT DO NOTHING'
            )->execute([$streamId, $id]);

            $pdo->prepare("UPDATE collabs SET status = 'scheduled' WHERE id = ? AND status <> 'done'")
                ->execute([$id]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('StreamOrg collab plan: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.server_error')], 500);
        }

        json_response([
            'ok'        => true,
            'stream_id' => $streamId,
            'message'   => __('ui.message.collab_planned'),
        ]);
    }

    private static function platformId(PDO $pdo, string $code): ?int
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id FROM streaming_platforms WHERE code = ? AND is_enabled');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Replaces a collab's cast with the given streamer ids, ours only. */
    private static function syncCast(PDO $pdo, int $collabId, mixed $ids): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', is_array($ids) ? $ids : []),
            static fn (int $v): bool => $v > 0
        )));

        $pdo->prepare('DELETE FROM collab_streamers WHERE collab_id = ?')->execute([$collabId]);

        if ($ids === []) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO collab_streamers (collab_id, streamer_id, role, confirmation)
             SELECT ?, s.id, ?, ?
               FROM streamers s WHERE s.id = ? AND s.user_id = ?
             ON CONFLICT DO NOTHING'
        );

        $roles = is_array($_POST['roles'] ?? null) ? $_POST['roles'] : [];

        foreach ($ids as $streamerId) {
            $role = $roles[$streamerId] ?? 'guest';
            $insert->execute([
                $collabId,
                in_array($role, self::ROLES, true) ? $role : 'guest',
                'invited',
                $streamerId,
                Auth::id(),
            ]);
        }
    }
}
