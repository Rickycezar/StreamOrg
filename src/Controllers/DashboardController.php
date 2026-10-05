<?php
declare(strict_types=1);

/**
 * The signed-in home page: shortcuts to create records, the content
 * planned for today, the next few days, and the loose ends that need a
 * decision. All dates are in the user's own time zone.
 */
final class DashboardController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $pdo    = Database::connection();
        $userId = (int) Auth::id();

        $today    = new DateTimeImmutable('today');
        $tomorrow = $today->modify('+1 day');
        $schedule = StreamSchedule::forUser($userId);

        $one = static function (string $sql, array $args = []) use ($pdo): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);

            return (int) $stmt->fetchColumn();
        };

        $stmt = $pdo->prepare(
            "SELECT s.id, s.title, s.status, s.scheduled_start, s.actual_start, s.ended_at, s.twitch_pushed_at,
                    s.deadline, sp.code AS platform_code, s.planned_minutes,
                    string_agg(g.title, ', ' ORDER BY sg.play_order) AS games,
                    (array_agg(h.path ORDER BY sg.play_order) FILTER (WHERE h.path IS NOT NULL))[1] AS art_path,
                    (array_agg(h.updated_at ORDER BY sg.play_order) FILTER (WHERE h.path IS NOT NULL))[1] AS art_version,
                    count(sg.game_id)                                  AS game_count,
                    count(sg.game_id) FILTER (WHERE sg.game_key_id IS NULL) AS unkeyed,
                    bool_or(emb.lifts_at IS NOT NULL AND s.scheduled_start < emb.lifts_at) AS breaks_embargo
               FROM streams s
               JOIN streaming_platforms sp ON sp.id = s.streaming_platform_id
          LEFT JOIN stream_games sg        ON sg.stream_id = s.id
          LEFT JOIN games g                ON g.id = sg.game_id
          LEFT JOIN game_images h          ON h.game_id = g.id AND h.kind = 'header'
          LEFT JOIN game_embargoes emb     ON emb.game_id = sg.game_id AND emb.user_id = s.user_id
              WHERE s.user_id = :user AND s.status <> 'cancelled'
                AND s.scheduled_start >= :from AND s.scheduled_start < :to
           GROUP BY s.id, sp.code
           ORDER BY s.scheduled_start"
        );
        $stmt->execute([
            'user' => $userId,
            'from' => $today->format(DATE_ATOM),
            'to'   => $tomorrow->format(DATE_ATOM),
        ]);

        $zone           = new DateTimeZone(date_default_timezone_get());
        $contentMinutes = ContentDefaults::minutes($userId);

        $todayItems = array_map(static function (array $row) use ($contentMinutes, $zone): array {
            $row['art'] = $row['art_path'] !== null
                ? GameImages::publicUrl($row['art_path'], (string) $row['art_version'])
                : null;

            $start = (new DateTimeImmutable($row['scheduled_start']))->setTimezone($zone);
            $row['expected_end'] = $row['ended_at'] !== null
                ? (new DateTimeImmutable($row['ended_at']))->setTimezone($zone)
                : $start->modify('+' . ($row['planned_minutes'] ?? $contentMinutes) . ' minutes');

            return $row;
        }, $stmt->fetchAll());

        $stmt = $pdo->prepare(
            "SELECT s.id, s.title, s.deadline,
                    string_agg(g.title, ', ' ORDER BY sg.play_order) AS games,
                    (array_agg(t.path ORDER BY sg.play_order) FILTER (WHERE t.path IS NOT NULL))[1] AS thumb_path,
                    (array_agg(t.updated_at ORDER BY sg.play_order) FILTER (WHERE t.path IS NOT NULL))[1] AS thumb_version
               FROM streams s
          LEFT JOIN stream_games sg ON sg.stream_id = s.id
          LEFT JOIN games g         ON g.id = sg.game_id
          LEFT JOIN game_images t   ON t.game_id = g.id AND t.kind = 'thumb'
              WHERE s.user_id = ? AND s.status = 'planned' AND s.scheduled_start IS NULL
           GROUP BY s.id
           ORDER BY s.deadline NULLS LAST, s.created_at
              LIMIT 30"
        );
        $stmt->execute([$userId]);

        $backlog = array_map(static function (array $row): array {
            $row['thumb'] = $row['thumb_path'] !== null
                ? GameImages::publicUrl($row['thumb_path'], (string) $row['thumb_version'])
                : null;

            return $row;
        }, $stmt->fetchAll());

        $stmt = $pdo->prepare(
            "SELECT kind, label, at FROM (
                 SELECT 'content' AS kind, s.title AS label, s.scheduled_start AS at
                   FROM streams s
                  WHERE s.user_id = :u AND s.status = 'planned'
                    AND s.scheduled_start >= :from AND s.scheduled_start < :to
                  UNION ALL
                 SELECT 'embargo', g.title, e.lifts_at
                   FROM game_embargoes e JOIN games g ON g.id = e.game_id
                  WHERE e.user_id = :u AND e.lifts_at >= :from AND e.lifts_at < :to
                  UNION ALL
                 SELECT 'deadline', s.title, s.deadline
                   FROM streams s
                  WHERE s.user_id = :u AND s.status = 'planned'
                    AND s.deadline >= :from AND s.deadline < :to
                  UNION ALL
                 SELECT 'expiry', g.title, k.expires_at
                   FROM game_keys k JOIN games g ON g.id = k.game_id
                  WHERE k.user_id = :u AND k.status = 'available'
                    AND k.expires_at >= :from AND k.expires_at < :to
             ) upcoming
             ORDER BY at
             LIMIT 8"
        );
        $stmt->execute([
            'u'    => $userId,
            'from' => $tomorrow->format(DATE_ATOM),
            'to'   => $today->modify('+8 days')->format(DATE_ATOM),
        ]);
        $upcoming = $stmt->fetchAll();

        $attention = [
            'undated'      => $one(
                "SELECT count(*) FROM streams WHERE user_id = ? AND status = 'planned' AND scheduled_start IS NULL",
                [$userId]
            ),
            'unkeyed'      => $one(
                "SELECT count(*) FROM stream_games sg JOIN streams s ON s.id = sg.stream_id
                  WHERE s.user_id = ? AND s.status = 'planned' AND sg.game_key_id IS NULL",
                [$userId]
            ),
            'conflicts'    => $one(
                'SELECT count(DISTINCT stream_id) FROM content_key_warnings
                  WHERE user_id = ? AND (breaks_embargo OR expires_before_use OR misses_deadline OR overdue OR impossible_window)',
                [$userId]
            ),
            'placeholders' => $one('SELECT count(*) FROM game_keys WHERE user_id = ? AND is_placeholder', [$userId]),
        ];

        $hour = (int) (new DateTimeImmutable())->format('G');

        View::render('dashboard', [
            'greeting'   => $hour < 5 ? 'evening' : ($hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening')),
            'name'       => (string) (Auth::user()['display_name'] ?: Auth::user()['username']),
            'today'      => $today,
            'todayItems' => $todayItems,
            'streamDay'  => $schedule[(int) $today->format('N')] ?? null,
            'hasSchedule' => $schedule !== [],
            'pickStart'  => StreamSchedule::startOn($schedule, $today),
            'backlog'    => $backlog,
            'twitchLogin' => TwitchUser::connection($userId)['twitch_login'] ?? null,
            'onAir'      => $one('SELECT count(*) FROM twitch_connections WHERE user_id = ? AND is_live', [$userId]) > 0
                ? ['category_name' => self::category($userId)] : null,
            'upcoming'   => $upcoming,
            'attention'  => $attention,
        ], __('ui.nav.dashboard'));
    }

    private static function category(int $userId): ?string
    {
        $stmt = Database::connection()->prepare('SELECT category_name FROM twitch_connections WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetchColumn() ?: null;
    }
}
