<?php
declare(strict_types=1);

/**
 * Planned content: the streams and videos you intend to make.
 *
 * A piece of content may cover a game or not (a chat-only stream), and a
 * covered game may or may not have a key attached — stream_games.game_key_id
 * is nullable, so "I plan to play this but have no key yet" is a first-class
 * state rather than a missing row.
 */
final class ContentController
{
    private const STATUSES = ['planned', 'live', 'done', 'cancelled'];

    /** Twitch refuses longer stream titles. */
    public const TWITCH_TITLE_MAX = 140;

    /**
     * Content with what the planner shows about it: games, the first game's
     * thumbnail, and the same warnings as the list (no key, before the
     * embargo, key expiring first). Callers add conditions, then GROUP BY.
     */
    private static function plannerSql(): string
    {
        return "SELECT s.id, s.title, s.status, s.scheduled_start, s.deadline, s.collab_session_id, sp.code AS platform_code,
                    coalesce(s.planned_minutes, (SELECT u.content_minutes FROM users u WHERE u.id = s.user_id)) AS minutes,
                    string_agg(g.title, ', ' ORDER BY sg.play_order) AS games,
                    (array_agg(t.path ORDER BY sg.play_order) FILTER (WHERE t.path IS NOT NULL))[1] AS thumb_path,
                    (array_agg(t.updated_at ORDER BY sg.play_order) FILTER (WHERE t.path IS NOT NULL))[1] AS thumb_version,
                    count(sg.game_id) FILTER (WHERE sg.game_key_id IS NULL) AS unkeyed,
                    max(emb.lifts_at)                                       AS embargo_until,
                    bool_or(s.scheduled_start IS NOT NULL
                            AND emb.lifts_at IS NOT NULL
                            AND s.scheduled_start < emb.lifts_at)           AS breaks_embargo,
                    bool_or(s.scheduled_start IS NOT NULL
                            AND k.expires_at IS NOT NULL
                            AND k.status = 'available'
                            AND k.expires_at < s.scheduled_start)           AS expires_before_use
               FROM streams s
               JOIN streaming_platforms sp ON sp.id = s.streaming_platform_id
          LEFT JOIN stream_games sg        ON sg.stream_id = s.id
          LEFT JOIN games g                ON g.id = sg.game_id
          LEFT JOIN game_images t          ON t.game_id = g.id AND t.kind = 'thumb'
          LEFT JOIN game_keys k            ON k.id = sg.game_key_id
          LEFT JOIN game_embargoes emb     ON emb.game_id = sg.game_id AND emb.user_id = s.user_id
                                          AND (emb.game_platform_id IS NULL
                                               OR k.game_platform_id IS NULL
                                               OR emb.game_platform_id = k.game_platform_id)
              WHERE s.user_id = :user";
    }

    public static function index(): void
    {
        Auth::requireLogin();

        $pdo    = Database::connection();
        $userId = Auth::id();

        $filters = [
            'status'   => (string) ($_GET['status'] ?? ''),
            'platform' => (string) ($_GET['platform'] ?? ''),
            'keyed'    => (string) ($_GET['keyed'] ?? ''),
            'dated'    => (string) ($_GET['dated'] ?? ''),
            'q'        => trim((string) ($_GET['q'] ?? '')),
        ];

        $stmt = $pdo->prepare('SELECT status, count(*) AS total FROM streams WHERE user_id = ? GROUP BY status');
        $stmt->execute([$userId]);
        $counts = array_column($stmt->fetchAll(), 'total', 'status');

        $stmt = $pdo->prepare(
            "SELECT count(*) FROM stream_games sg
               JOIN streams s ON s.id = sg.stream_id
              WHERE s.user_id = ? AND s.status = 'planned' AND sg.game_key_id IS NULL"
        );
        $stmt->execute([$userId]);
        $missingKeys = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT count(*) FROM streams
              WHERE user_id = ? AND status = 'planned'
                AND scheduled_start BETWEEN now() AND now() + interval '7 days'"
        );
        $stmt->execute([$userId]);
        $thisWeek = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT count(DISTINCT stream_id) FILTER (WHERE breaks_embargo)     AS embargo,
                    count(DISTINCT stream_id) FILTER (WHERE expires_before_use) AS expiring,
                    count(DISTINCT stream_id) FILTER (WHERE misses_deadline)    AS late,
                    count(DISTINCT stream_id) FILTER (WHERE overdue)            AS overdue,
                    count(DISTINCT stream_id) FILTER (WHERE impossible_window)  AS impossible
               FROM content_key_warnings WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $warnings = $stmt->fetch() ?: [];
        $warningTotal = array_sum(array_map('intval', $warnings ?: []));

        $stmt = $pdo->prepare(
            "SELECT count(*) FROM streams
              WHERE user_id = ? AND status = 'planned' AND scheduled_start IS NULL"
        );
        $stmt->execute([$userId]);
        $undated = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT count(DISTINCT game_id) FROM game_embargoes
              WHERE user_id = ? AND lifts_at > now()'
        );
        $stmt->execute([$userId]);
        $embargoed = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(self::plannerSql() . "
                AND s.status = 'planned' AND s.scheduled_start IS NULL
           GROUP BY s.id, sp.code
           ORDER BY s.deadline NULLS LAST, s.created_at");
        $stmt->execute(['user' => $userId]);
        $backlog = $stmt->fetchAll();

        $sql = "SELECT s.id, s.title, s.title_template, s.collab_session_id, s.status, s.scheduled_start, s.deadline, s.ended_at, s.vod_url, s.notes,
                       s.category_id, s.category_name,
                       sp.code AS platform_code,
                       count(sg.game_id)                                     AS game_count,
                       count(sg.game_key_id)                                 AS keyed_count,
                       max(emb.lifts_at)                                     AS embargo_until,
                       min(k.expires_at)                                     AS expires_at,
                       bool_or(s.scheduled_start IS NOT NULL
                               AND emb.lifts_at IS NOT NULL
                               AND s.scheduled_start < emb.lifts_at)         AS breaks_embargo,
                       bool_or(emb.lifts_at IS NOT NULL AND s.deadline IS NOT NULL
                               AND emb.lifts_at > s.deadline)                AS impossible_window,
                       (s.scheduled_start IS NOT NULL AND s.deadline IS NOT NULL
                        AND s.scheduled_start > s.deadline)                  AS misses_deadline,
                       (s.scheduled_start IS NULL AND s.deadline IS NOT NULL
                        AND s.deadline < now())                              AS overdue,
                       string_agg(DISTINCT g.title, ', ')                    AS games,
                       EXISTS (SELECT 1 FROM stream_collaborators c WHERE c.stream_id = s.id) AS is_collab
                  FROM streams s
                  JOIN streaming_platforms sp ON sp.id = s.streaming_platform_id
             LEFT JOIN stream_games sg        ON sg.stream_id = s.id
             LEFT JOIN games g                ON g.id = sg.game_id
             LEFT JOIN game_keys k            ON k.id = sg.game_key_id
             LEFT JOIN game_embargoes emb     ON emb.game_id = sg.game_id AND emb.user_id = s.user_id
                                             AND (emb.game_platform_id IS NULL
                                                  OR k.game_platform_id IS NULL
                                                  OR emb.game_platform_id = k.game_platform_id)
                 WHERE s.user_id = :user";

        $params = ['user' => $userId];

        if (in_array($filters['status'], self::STATUSES, true)) {
            $sql .= ' AND s.status = :status';
            $params['status'] = $filters['status'];
        }

        if ($filters['platform'] !== '') {
            $sql .= ' AND sp.code = :platform';
            $params['platform'] = $filters['platform'];
        }

        if ($filters['q'] !== '') {
            $sql .= ' AND (s.title ILIKE :q OR g.title ILIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if ($filters['dated'] === 'undated') {
            $sql .= ' AND s.scheduled_start IS NULL';
        } elseif ($filters['dated'] === 'dated') {
            $sql .= ' AND s.scheduled_start IS NOT NULL';
        }

        $sql .= ' GROUP BY s.id, sp.code';

        if ($filters['keyed'] === 'with') {
            $sql .= ' HAVING count(sg.game_key_id) > 0';
        } elseif ($filters['keyed'] === 'without') {
            $sql .= ' HAVING count(sg.game_key_id) = 0';
        }

        $sql .= ' ORDER BY s.scheduled_start ASC NULLS FIRST, s.deadline ASC NULLS LAST, s.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $content = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT k.id, k.game_id, k.key_type, k.status, k.expires_at,
                    gp.code AS platform_code, kp.code AS source_code
               FROM game_keys k
               JOIN game_platforms gp ON gp.id = k.game_platform_id
               JOIN key_platforms kp  ON kp.id = k.key_platform_id
              WHERE k.user_id = ?
                AND k.status IN ('available', 'reserved', 'for_giveaway', 'used')
           ORDER BY CASE k.status
                        WHEN 'available'    THEN 1
                        WHEN 'reserved'     THEN 2
                        WHEN 'for_giveaway' THEN 3
                        ELSE 4
                    END, gp.code"
        );
        $stmt->execute([$userId]);
        $keys = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT game_id, lifts_at FROM game_embargoes
              WHERE user_id = ? AND kind = 'release' AND game_platform_id IS NULL"
        );
        $stmt->execute([$userId]);
        $embargoByGame = array_column($stmt->fetchAll(), 'lifts_at', 'game_id');

        $stmt = $pdo->prepare(
            "SELECT c.id, c.title, c.proposed_at, sp.code AS platform_code,
                    string_agg(st.name, '|' ORDER BY st.name) AS cast_names
               FROM collabs c
          LEFT JOIN streaming_platforms sp ON sp.id = c.streaming_platform_id
               JOIN collab_streamers cs    ON cs.collab_id = c.id
               JOIN streamers st           ON st.id = cs.streamer_id
              WHERE c.user_id = ? AND c.status <> 'cancelled'
           GROUP BY c.id, sp.code
           ORDER BY c.title"
        );
        $stmt->execute([$userId]);
        $collabs = $stmt->fetchAll();

        View::render('content/index', [
            'counts'      => $counts,
            'missingKeys' => $missingKeys,
            'thisWeek'    => $thisWeek,
            'warnings'      => $warnings,
            'warningTotal'  => $warningTotal,
            'undated'       => $undated,
            'embargoed'     => $embargoed,
            'defaultDeadline' => (new DateTimeImmutable('+30 days'))->format('Y-m-d\\TH:i'),
            'defaultEmbargo'  => (new DateTimeImmutable('today'))->format('Y-m-d\\TH:i'),
            'embargoByGame' => $embargoByGame,
            'backlog'     => $backlog,
            'schedule'    => StreamSchedule::forUser((int) $userId),
            'contentMinutes' => ContentDefaults::minutes((int) $userId),
            'prefixes'    => ContentDefaults::prefixes((int) $userId),
            'timeline'    => TitleCounters::timeline((int) $userId),
            'collabPrefix' => ContentDefaults::collabPrefix((int) $userId),
            'twitchSchedule' => TwitchSchedule::status((int) $userId),
            'twitchCategories' => Twitch::isConfigured(),
            'content'     => $content,
            'filters'     => $filters,
            'statuses'    => self::STATUSES,
            'keyStatuses' => KeysController::STATUSES,
            'platforms'   => $pdo->query('SELECT code FROM streaming_platforms WHERE is_enabled ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN),
            'collabs'     => $collabs,
            'preselect'   => filter_input(INPUT_GET, 'collab', FILTER_VALIDATE_INT) ?: null,
            'keys'        => $keys,
            'keySources'  => $pdo->query('SELECT code FROM key_platforms ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN),
            'tagSources'  => $pdo->query(
                'SELECT code FROM key_platforms WHERE tags_content ORDER BY sort_order'
            )->fetchAll(PDO::FETCH_COLUMN),
            'gamePlatforms' => $pdo->query('SELECT code FROM game_platforms ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN),
            'canAddGames' => CatalogController::canAddGames(),
            'twitchLogin' => TwitchUser::connection((int) Auth::id())['twitch_login'] ?? null,
        ], __('ui.nav.content'));
    }

    public static function store(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $title    = self::oneLine((string) ($_POST['title'] ?? ''));
        $platform = trim((string) ($_POST['platform'] ?? ''));
        $template = TitleCounters::templateFor((int) Auth::id(), $title);

        if ($title === '' || $platform === '') {
            flash('error', __('ui.message.invalid_input'));
            redirect('/content');
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT id FROM streaming_platforms WHERE code = ? AND is_enabled');
        $stmt->execute([$platform]);
        $platformId = $stmt->fetchColumn();

        if ($platformId === false) {
            flash('error', __('ui.message.invalid_input'));
            redirect('/content');
        }

        $status    = in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : 'planned';
        $scheduled = trim((string) ($_POST['scheduled_start'] ?? ''));
        $gameId    = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT) ?: null;
        $keyId     = filter_input(INPUT_POST, 'game_key_id', FILTER_VALIDATE_INT) ?: null;

        $deadline = trim((string) ($_POST['deadline'] ?? ''));

        if ($deadline === '') {
            $deadline = (new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:sP');
        }

        $category = self::postedCategory();

        if ($category === false) {
            flash('error', __('ui.message.invalid_input'));
            redirect('/content');
        }

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO streams (user_id, streaming_platform_id, title, title_template, status,
                                      scheduled_start, deadline, notes, category_id, category_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
            );
            $stmt->execute([
                Auth::id(),
                $platformId,
                $title,
                $template,
                $status,
                $scheduled !== '' ? $scheduled : null,
                $deadline,
                trim((string) ($_POST['notes'] ?? '')) ?: null,
                $category[0],
                $category[1],
            ]);

            $streamId = (int) $stmt->fetchColumn();

            self::assertTitleFits($pdo, $streamId, $platform);

            $collabId = filter_input(INPUT_POST, 'collab_id', FILTER_VALIDATE_INT);

            if ($collabId) {
                $check = $pdo->prepare('SELECT 1 FROM collabs WHERE id = ? AND user_id = ?');
                $check->execute([$collabId, Auth::id()]);

                if ($check->fetchColumn() !== false) {
                    $pdo->prepare('UPDATE streams SET collab_id = ? WHERE id = ?')
                        ->execute([$collabId, $streamId]);

                    $pdo->prepare(
                        'INSERT INTO stream_collaborators (stream_id, streamer_id, role, confirmation, note)
                         SELECT ?, cs.streamer_id, cs.role, cs.confirmation, cs.note
                           FROM collab_streamers cs WHERE cs.collab_id = ?
                         ON CONFLICT DO NOTHING'
                    )->execute([$streamId, $collabId]);

                    $pdo->prepare("UPDATE collabs SET status = 'scheduled' WHERE id = ? AND status <> 'done'")
                        ->execute([$collabId]);
                }
            }

            if ($gameId !== null) {
                if ($keyId !== null) {
                    $check = $pdo->prepare('SELECT 1 FROM game_keys WHERE id = ? AND user_id = ? AND game_id = ?');
                    $check->execute([$keyId, Auth::id(), $gameId]);

                    if ($check->fetchColumn() === false) {
                        $keyId = null;
                    }
                }

                $pdo->prepare(
                    'INSERT INTO stream_games (stream_id, game_id, game_key_id, play_order)
                     VALUES (?, ?, ?, 1) ON CONFLICT DO NOTHING'
                )->execute([$streamId, $gameId, $keyId]);

                $pdo->prepare(
                    "INSERT INTO game_coverage (user_id, game_id, status)
                     VALUES (?, ?, 'scheduled')
                     ON CONFLICT (user_id, game_id) DO UPDATE
                        SET status = 'scheduled'
                      WHERE game_coverage.status IN ('wishlist', 'key_requested', 'key_received')"
                )->execute([Auth::id(), $gameId]);

                if (array_key_exists('embargo_until', $_POST)) {
                    self::writeReleaseEmbargo($pdo, $gameId, (string) $_POST['embargo_until']);
                }
            }

            $pdo->commit();
        } catch (UserError $e) {
            $pdo->rollBack();
            flash('error', $e->getMessage());
            redirect('/content');
        } catch (Throwable $e) {
            $pdo->rollBack();
            ErrorLog::note('content: ' . $e->getMessage());
            flash('error', __('ui.message.server_error'));
            redirect('/content');
        }

        flash('success', __('ui.message.saved'));
        redirect('/content');
    }

    /**
     * Twitch refuses titles over 140 characters: checks the finished title
     * (prefixes and numbers included) inside the saving transaction.
     *
     * @throws UserError
     */
    private static function assertTitleFits(PDO $pdo, int $streamId, string $platform): void
    {
        if ($platform !== 'twitch') {
            return;
        }

        $stmt = $pdo->prepare('SELECT title FROM streams WHERE id = ?');
        $stmt->execute([$streamId]);
        $length = mb_strlen((string) $stmt->fetchColumn());

        if ($length > self::TWITCH_TITLE_MAX) {
            throw new UserError(sprintf(__('ui.message.twitch_title_too_long'), $length, self::TWITCH_TITLE_MAX));
        }
    }

    /** Collapses newlines and runs of whitespace into single spaces. */
    /**
     * The Twitch category chosen in a content form: [id, name], [null, null]
     * for none, or false when the id is not a Twitch category.
     *
     * @return array{0:?string, 1:?string}|false
     */
    private static function postedCategory(): array|false
    {
        $id = trim((string) ($_POST['category_id'] ?? ''));

        if ($id === '') {
            return [null, null];
        }

        $category = Twitch::category($id);

        return $category === null ? false : [$category['id'], $category['name']];
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** AJAX: move content to another status. */
    public static function updateStatus(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');

        if ($id === false || $id === null || !in_array($status, self::STATUSES, true)) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare('UPDATE streams SET status = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$status, $id, Auth::id()]);

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        if ($status === 'done') {
            $pdo->prepare(
                "UPDATE game_coverage c SET status = 'streamed'
                   FROM stream_games sg
                  WHERE sg.stream_id = ? AND sg.game_id = c.game_id
                    AND c.user_id = ? AND c.status <> 'covered'"
            )->execute([$id, Auth::id()]);
        }

        json_response([
            'ok'     => true,
            'status' => $status,
            'label'  => code_label('stream_status', $status),
        ]);
    }

    /**
     * AJAX: everything about one piece of content, for the detail modal.
     * Returns data rather than markup so the client builds DOM via
     * textContent and nothing user-supplied is ever parsed as HTML.
     */
    public static function show(): void
    {
        Auth::requireLogin();

        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if ($id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT s.*, sp.code AS platform_code, c.title AS collab_title
               FROM streams s
               JOIN streaming_platforms sp ON sp.id = s.streaming_platform_id
          LEFT JOIN collabs c              ON c.id = s.collab_id
              WHERE s.id = ? AND s.user_id = ?'
        );
        $stmt->execute([$id, Auth::id()]);
        $content = $stmt->fetch();

        if ($content === false) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        $stmt = $pdo->prepare(
            "SELECT g.id, g.title, g.release_date, sg.play_order, sg.note,
                    k.id AS key_id, k.key_type, k.content_type, k.status AS key_status,
                    k.expires_at, gp.code AS key_platform_code, kp.code AS key_source_code,
                    emb.lifts_at AS embargo_until, emb.kind AS embargo_kind,
                    emb.label AS embargo_label, cov.status AS coverage_status
               FROM stream_games sg
               JOIN games g               ON g.id = sg.game_id
          LEFT JOIN game_keys k           ON k.id = sg.game_key_id
          LEFT JOIN game_platforms gp     ON gp.id = k.game_platform_id
          LEFT JOIN key_platforms kp      ON kp.id = k.key_platform_id
          LEFT JOIN game_coverage cov     ON cov.game_id = g.id AND cov.user_id = :user2
          LEFT JOIN LATERAL (
                  SELECT e.lifts_at, e.kind, e.label FROM game_embargoes e
                   WHERE e.game_id = g.id AND e.user_id = :user3
                     AND (e.game_platform_id IS NULL
                          OR k.game_platform_id IS NULL
                          OR e.game_platform_id = k.game_platform_id)
                ORDER BY e.lifts_at DESC LIMIT 1
              ) emb ON true
              WHERE sg.stream_id = :stream
           ORDER BY sg.play_order"
        );
        $stmt->execute(['user2' => Auth::id(), 'user3' => Auth::id(), 'stream' => $id]);
        $games = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT st.name, sc.role, sc.confirmation
               FROM stream_collaborators sc
               JOIN streamers st ON st.id = sc.streamer_id
              WHERE sc.stream_id = ?'
        );
        $stmt->execute([$id]);
        $collaborators = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT game_title, breaks_embargo, expires_before_use,
                    misses_deadline, overdue, impossible_window
               FROM content_key_warnings WHERE stream_id = ? AND user_id = ?'
        );
        $stmt->execute([$id, Auth::id()]);

        $problems = [];

        foreach ($stmt->fetchAll() as $row) {
            foreach (['breaks_embargo', 'expires_before_use', 'misses_deadline', 'overdue', 'impossible_window'] as $flag) {
                if ($row[$flag]) {
                    $problems[$flag] = __('ui.message.' . $flag);
                }
            }
        }

        json_response([
            'ok'      => true,
            'content' => [
                'id'        => (int) $content['id'],
                'title'     => $content['title'],
                'status'    => $content['status'],
                'status_label'   => code_label('stream_status', $content['status']),
                'platform_label' => code_label('streaming_platform', $content['platform_code']),
                'scheduled' => $content['scheduled_start'] ? fmt_datetime($content['scheduled_start']) : null,
                'deadline'  => $content['deadline'] ? fmt_datetime($content['deadline']) : null,
                'started'   => $content['actual_start'] ? fmt_datetime($content['actual_start']) : null,
                'ended'     => $content['ended_at'] ? fmt_datetime($content['ended_at']) : null,
                'vod_url'   => safe_url($content['vod_url']),
                'notes'     => $content['notes'],
                'collab'    => $content['collab_title'],
                'peak'      => $content['peak_viewers'],
                'avg'       => $content['avg_viewers'],
                'created'   => fmt_datetime($content['created_at']),
                'updated'   => fmt_datetime($content['updated_at']),
            ],
            'images' => (object) ($games === [] ? [] : GameImages::urls($pdo, (int) $games[0]['id'])),
            'games' => array_map(static fn (array $g): array => [
                'title'        => $g['title'],
                'images'       => (object) GameImages::urls($pdo, (int) $g['id']),
                'release_date' => $g['release_date'] ? fmt_date($g['release_date']) : null,
                'note'         => $g['note'],
                'coverage'     => $g['coverage_status'] ? code_label('coverage_status', $g['coverage_status']) : null,
                'embargo'      => $g['embargo_until']
                    ? fmt_datetime($g['embargo_until'])
                        . ' (' . code_label('embargo_kind', $g['embargo_kind'])
                        . ($g['embargo_label'] ? ' — ' . $g['embargo_label'] : '') . ')'
                    : null,
                'key'          => $g['key_id'] === null ? null : [
                    'type'      => code_label('key_type', $g['key_type']),
                    'content'   => code_label('content_type', $g['content_type']),
                    'status'    => code_label('key_status', $g['key_status']),
                    'platform'  => code_label('game_platform', $g['key_platform_code']),
                    'source'    => code_label('key_platform', $g['key_source_code']),
                    'expires'   => $g['expires_at'] ? fmt_date($g['expires_at']) : null,
                ],
            ], $games),
            'collaborators' => array_map(static fn (array $c): array => [
                'name'         => $c['name'],
                'role'         => code_label('collab_role', $c['role']),
                'confirmation' => code_label('confirmation', $c['confirmation']),
            ], $collaborators),
            'problems' => array_values($problems),
        ]);
    }

    /**
     * Writes the game's all-platform release embargo.
     *
     * Blank means no restriction, stored as today rather than deleting the
     * row, so a game always carries an explicit answer. Marked manual so a
     * later API refresh will not overwrite a date set by hand.
     */
    private static function writeReleaseEmbargo(PDO $pdo, int $gameId, string $value): void
    {
        $lifts = trim($value);

        if ($lifts === '') {
            $lifts = (new DateTimeImmutable('today'))->format('Y-m-d H:i:sP');
        }

        $pdo->prepare(
            "INSERT INTO game_embargoes (user_id, game_id, kind, lifts_at, source)
             VALUES (?, ?, 'release', ?, 'manual')
             ON CONFLICT (user_id, game_id, kind, COALESCE(game_platform_id, 0))
             DO UPDATE SET lifts_at = EXCLUDED.lifts_at, source = 'manual'"
        )->execute([Auth::id(), $gameId, $lifts]);
    }

    /** AJAX: inline edit of one piece of content. */
    public static function update(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $title = self::oneLine((string) ($_POST['title'] ?? ''));

        if ($title === '') {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT id FROM streaming_platforms WHERE code = ? AND is_enabled');
        $stmt->execute([(string) ($_POST['platform'] ?? '')]);
        $platformId = $stmt->fetchColumn();

        if ($platformId === false) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $status    = in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : 'planned';
        $scheduled = trim((string) ($_POST['scheduled_start'] ?? '')) ?: null;
        $deadline  = trim((string) ($_POST['deadline'] ?? '')) ?: null;

        $template = TitleCounters::templateFor((int) Auth::id(), $title);
        $notice   = null;

        $current = $pdo->prepare('SELECT scheduled_start, collab_session_id FROM streams WHERE id = ? AND user_id = ?');
        $current->execute([$id, Auth::id()]);
        $before = $current->fetch();

        if ($before !== false && $before['collab_session_id'] !== null) {
            $was = $before['scheduled_start'] !== null ? (new DateTimeImmutable($before['scheduled_start']))->getTimestamp() : null;
            $now = $scheduled !== null ? (new DateTimeImmutable($scheduled))->getTimestamp() : null;

            if ($was !== $now) {
                try {
                    $notice = CollabSessions::guardTimeChange((int) Auth::id(), $id, $scheduled !== null ? new DateTimeImmutable($scheduled) : null, null);
                } catch (UserError $e) {
                    json_response(['ok' => false, 'error' => $e->getMessage()], 409);
                }

                if ($notice !== null) {
                    $scheduled = $before['scheduled_start'];
                }
            }
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'UPDATE streams
                SET title = :title, streaming_platform_id = :platform, status = :status,
                    scheduled_start = :scheduled, deadline = :deadline,
                    vod_url = :vod, notes = :notes, title_template = :template
              WHERE id = :id AND user_id = :user'
        );

        $stmt->execute([
            'template'  => $template,
            'title'     => $title,
            'platform'  => $platformId,
            'status'    => $status,
            'scheduled' => $scheduled,
            'deadline'  => $deadline,
            'vod'       => safe_url($_POST['vod_url'] ?? null),
            'notes'     => trim((string) ($_POST['notes'] ?? '')) ?: null,
            'id'        => $id,
            'user'      => Auth::id(),
        ]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        try {
            self::assertTitleFits($pdo, $id, (string) ($_POST['platform'] ?? ''));
            $pdo->commit();
        } catch (UserError $e) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        if (array_key_exists('category_id', $_POST)) {
            $category = self::postedCategory();

            if ($category === false) {
                json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
            }

            $pdo->prepare('UPDATE streams SET category_id = ?, category_name = ? WHERE id = ? AND user_id = ?')
                ->execute([$category[0], $category[1], $id, Auth::id()]);
        }

        if (array_key_exists('embargo_until', $_POST)) {
            $rawEmbargo = (string) $_POST['embargo_until'];

            $games = $pdo->prepare(
                'SELECT sg.game_id FROM stream_games sg
                   JOIN streams s ON s.id = sg.stream_id
                  WHERE sg.stream_id = ? AND s.user_id = ?'
            );
            $games->execute([$id, Auth::id()]);

            foreach ($games->fetchAll(PDO::FETCH_COLUMN) as $gameId) {
                self::writeReleaseEmbargo($pdo, (int) $gameId, $rawEmbargo);
            }
        }

        json_response(['ok' => true, 'message' => $notice !== null ? __('ui.message.saved') . ' ' . $notice : __('ui.message.saved')]);
    }

    /**
     * AJAX: delete one piece of content.
     *
     * The games and collaborators attached to it cascade away with it;
     * the keys themselves do not, they simply become unattached. Title
     * numbers after it move down on their own (streamorg_renumber_titles).
     * Content planned together with others leaves that joint plan first
     * (the host's cancels it), so the others are told.
     */
    public static function delete(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $linked = Database::connection()->prepare('SELECT collab_session_id FROM streams WHERE id = ? AND user_id = ?');
        $linked->execute([$id, Auth::id()]);
        $sessionId = $linked->fetchColumn();

        if ($sessionId) {
            try {
                CollabSessions::leave((int) Auth::id(), (int) $sessionId);
            } catch (UserError) {
            }
        }

        $deleted = Database::transaction(static function (PDO $pdo) use ($id): bool {
            $own = $pdo->prepare('SELECT 1 FROM streams WHERE id = ? AND user_id = ? FOR UPDATE');
            $own->execute([$id, Auth::id()]);

            if ($own->fetchColumn() === false) {
                return false;
            }

            $pdo->prepare('DELETE FROM streams WHERE id = ? AND user_id = ?')->execute([$id, Auth::id()]);

            return true;
        });

        if (!$deleted) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        json_response(['ok' => true, 'message' => __('ui.message.deleted')]);
    }

    /**
     * AJAX: what "Send to Twitch" would change, for the confirmation step.
     * Reads the channel but changes nothing.
     */
    public static function twitchPreview(): void
    {
        Auth::requireLogin();

        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$id) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        try {
            $plan = TwitchPush::plan((int) Auth::id(), $id, (string) ($_GET['category'] ?? '') ?: null);
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            ErrorLog::note('Twitch preview: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.server_error')], 500);
        }

        json_response(['ok' => true, 'plan' => $plan]);
    }

    /** AJAX: sends the stream's title, category and sponsor tags to Twitch. */
    public static function twitchPush(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if (!$id) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        try {
            $plan = TwitchPush::push((int) Auth::id(), $id, (string) ($_POST['category'] ?? '') ?: null);
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            ErrorLog::note('Twitch push: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.server_error')], 500);
        }

        json_response([
            'ok'      => true,
            'status'  => $plan['status'],
            'message' => sprintf(__('ui.message.twitch_sent'), $plan['login'])
                . ($plan['status'] === 'live' ? ' ' . __('ui.message.twitch_marked_live') : ''),
        ]);
    }

    /** AJAX: sends the planned content to the Twitch channel schedule. */
    public static function twitchSchedule(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $userId = (int) Auth::id();

        try {
            $result = TwitchSchedule::send($userId);
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $done = $result['created'] + $result['updated'] + $result['removed'] + $result['unchanged'];

        if ($result['failed'] > 0 && $done === 0) {
            json_response(['ok' => false, 'error' => sprintf(__('ui.message.twitch_schedule_failed'), (string) $result['error'])], 502);
        }

        json_response([
            'ok'      => true,
            'message' => sprintf(__('ui.message.twitch_schedule_sent'), $result['created'], $result['updated'], $result['removed'])
                . ($result['failed'] > 0 ? ' ' . sprintf(__('ui.message.twitch_schedule_some_failed'), $result['failed'], (string) $result['error']) : ''),
            'status'  => TwitchSchedule::describe(TwitchSchedule::status($userId)),
        ]);
    }

    /**
     * The calendar works in "wall-clock" time: dates go to the browser as
     * local times in the user's profile time zone, with no offset, and come
     * back the same way. The browser's own zone never enters into it, so
     * 20:00 means 20:00 for the user wherever their computer thinks it is.
     */
    private static function toWall(?string $timestamp): ?string
    {
        return $timestamp === null ? null
            : (new DateTimeImmutable($timestamp))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d\TH:i:s');
    }

    /** "2026-10-05T20:00:00" in the user's zone -> a timestamp, or null when malformed. */
    private static function fromWall(string $wall): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s',
            substr($wall, 0, 19),
            new DateTimeZone(date_default_timezone_get())
        );

        return $date === false ? null : $date;
    }

    /**
     * One planner item, shaped as a FullCalendar event (and reused for the
     * backlog). Only planned content can be moved or resized; its length is
     * its own, or the user's usual content length.
     *
     * @return array<string,mixed>
     */
    public static function plannerItem(array $row): array
    {
        $warnings = array_values(array_filter([
            (int) $row['unkeyed'] > 0     ? __('ui.label.no_key') : null,
            $row['breaks_embargo']        ? __('ui.label.before_embargo') : null,
            $row['expires_before_use']    ? __('ui.label.expires_first') : null,
        ]));

        $start = self::toWall($row['scheduled_start']);

        return [
            'id'         => (string) $row['id'],
            'title'      => $row['title'],
            'start'      => $start,
            'end'        => $start === null ? null
                : (new DateTimeImmutable($start))->modify('+' . (int) $row['minutes'] . ' minutes')->format('Y-m-d\TH:i:s'),
            'editable'   => $row['status'] === 'planned',
            'classNames' => array_merge(['status-' . $row['status']], $warnings !== [] ? ['has-warning'] : [], $row['collab_session_id'] ? ['is-together'] : []),
            'extendedProps' => [
                'status'      => $row['status'],
                'together'    => $row['collab_session_id'] ? (int) $row['collab_session_id'] : null,
                'minutes'     => (int) $row['minutes'],
                'statusLabel' => code_label('stream_status', $row['status']),
                'games'       => $row['games'],
                'thumb'       => $row['thumb_path'] !== null
                    ? GameImages::publicUrl($row['thumb_path'], (string) $row['thumb_version'])
                    : null,
                'deadline'    => $row['deadline'] ? fmt_date($row['deadline']) : null,
                'warnings'    => $warnings,
            ],
        ];
    }

    /** GET: the user's dated content between FullCalendar's start and end. */
    public static function calendar(): void
    {
        Auth::requireLogin();

        $from = self::fromWall((string) ($_GET['start'] ?? ''));
        $to   = self::fromWall((string) ($_GET['end'] ?? ''));

        if ($from === null || $to === null || $to <= $from || $from->diff($to)->days > 62) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $stmt = Database::connection()->prepare(self::plannerSql() . "
                AND s.scheduled_start >= :from AND s.scheduled_start < :to
           GROUP BY s.id, sp.code
           ORDER BY s.scheduled_start");
        $stmt->execute([
            'user' => Auth::id(),
            'from' => $from->format(DATE_ATOM),
            'to'   => $to->format(DATE_ATOM),
        ]);

        json_response(array_map([self::class, 'plannerItem'], $stmt->fetchAll()));
    }

    /**
     * POST: sets or clears one item's date and time, from a drop on the
     * calendar or a drag back to the backlog, and with "minutes" its length
     * (a resize). Only planned content moves: what is live, done or
     * cancelled already happened (or will not).
     */
    public static function schedule(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id      = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $wall    = trim((string) ($_POST['start'] ?? ''));
        $when    = $wall === '' ? null : self::fromWall($wall);
        $rawLen  = trim((string) ($_POST['minutes'] ?? ''));
        $minutes = $rawLen === '' ? null : ContentDefaults::minutesFromInput($rawLen);

        if (!$id || ($wall !== '' && $when === null) || ($rawLen !== '' && $minutes === null)) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        try {
            $proposed = CollabSessions::guardTimeChange((int) Auth::id(), $id, $when, $minutes);
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 409);
        }

        if ($proposed !== null) {
            json_response(['ok' => false, 'error' => $proposed], 409);
        }

        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "UPDATE streams SET scheduled_start = ?, planned_minutes = coalesce(?, planned_minutes)
              WHERE id = ? AND user_id = ? AND status = 'planned'"
        );
        $stmt->execute([$when?->format(DATE_ATOM), $minutes, $id, Auth::id()]);

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.planner_not_movable')], 409);
        }

        $stmt = $pdo->prepare(self::plannerSql() . ' AND s.id = :id GROUP BY s.id, sp.code');
        $stmt->execute(['user' => Auth::id(), 'id' => $id]);

        json_response([
            'ok'    => true,
            'item'  => self::plannerItem($stmt->fetch()),
            'label' => $when === null ? null : fmt_datetime($when->format(DATE_ATOM)),
        ]);
    }
}
