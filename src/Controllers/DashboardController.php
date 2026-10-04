<?php
declare(strict_types=1);

final class DashboardController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $pdo    = Database::connection();
        $userId = Auth::id();

        $one = static function (string $sql, array $args = []) use ($pdo): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);

            return (int) $stmt->fetchColumn();
        };

        $rows = static function (string $sql, array $args = []) use ($pdo): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);

            return $stmt->fetchAll();
        };

        $stats = [
            'available' => $one("SELECT count(*) FROM game_keys WHERE user_id = ? AND status = 'available'", [$userId]),
            'giveaway'  => $one("SELECT count(*) FROM game_keys WHERE user_id = ? AND status = 'for_giveaway'", [$userId]),
            'planned'   => $one("SELECT count(*) FROM streams WHERE user_id = ? AND status = 'planned'", [$userId]),
            'undated'   => $one("SELECT count(*) FROM streams WHERE user_id = ? AND status = 'planned' AND scheduled_start IS NULL", [$userId]),
            'embargoed' => $one('SELECT count(DISTINCT game_id) FROM game_embargoes WHERE user_id = ? AND lifts_at > now()', [$userId]),
            'games'     => $one('SELECT count(*) FROM games'),
            'streamers' => $one('SELECT count(*) FROM streamers WHERE user_id = ?', [$userId]),
            'conflicts' => $one(
                'SELECT count(DISTINCT stream_id) FROM content_key_warnings
                  WHERE user_id = ? AND (breaks_embargo OR expires_before_use OR misses_deadline OR overdue OR impossible_window)',
                [$userId]
            ),
        ];

        $byStatus = array_map(
            static fn (array $r): array => [
                'label' => code_label('key_status', $r['status']),
                'value' => (int) $r['total'],
            ],
            $rows(
                'SELECT status, count(*) AS total FROM game_keys
                  WHERE user_id = ? GROUP BY status ORDER BY total DESC',
                [$userId]
            )
        );

        $bySource = array_map(
            static fn (array $r): array => [
                'label' => code_label('key_platform', $r['code']),
                'value' => (int) $r['total'],
            ],
            $rows(
                'SELECT kp.code, count(*) AS total
                   FROM game_keys k JOIN key_platforms kp ON kp.id = k.key_platform_id
                  WHERE k.user_id = ? GROUP BY kp.code ORDER BY total DESC LIMIT 8',
                [$userId]
            )
        );

        $byPlatform = array_map(
            static fn (array $r): array => [
                'label' => code_label('game_platform', $r['code']),
                'value' => (int) $r['total'],
            ],
            $rows(
                'SELECT gp.code, count(*) AS total
                   FROM game_keys k JOIN game_platforms gp ON gp.id = k.game_platform_id
                  WHERE k.user_id = ? GROUP BY gp.code ORDER BY total DESC LIMIT 8',
                [$userId]
            )
        );

        $byType = $rows(
            'SELECT key_type, count(*) AS total FROM game_keys
              WHERE user_id = ? GROUP BY key_type ORDER BY key_type',
            [$userId]
        );

        $coverage = array_map(
            static fn (array $r): array => [
                'label' => code_label('coverage_status', $r['status']),
                'value' => (int) $r['total'],
            ],
            $rows(
                'SELECT status, count(*) AS total FROM game_coverage
                  WHERE user_id = ? GROUP BY status ORDER BY total DESC',
                [$userId]
            )
        );

        $agenda = $rows(
            "SELECT 'content' AS kind, s.title AS label, s.scheduled_start AS at,
                    NULL AS extra
               FROM streams s
              WHERE s.user_id = :u AND s.status = 'planned' AND s.scheduled_start >= now()
              UNION ALL
             SELECT 'embargo', g.title, e.lifts_at, NULL
               FROM game_embargoes e JOIN games g ON g.id = e.game_id
              WHERE e.user_id = :u AND e.lifts_at > now()
              UNION ALL
             SELECT 'deadline', s.title, s.deadline, NULL
               FROM streams s
              WHERE s.user_id = :u AND s.status = 'planned' AND s.deadline >= now()
              UNION ALL
             SELECT 'expiry', g.title, k.expires_at, NULL
               FROM game_keys k JOIN games g ON g.id = k.game_id
              WHERE k.user_id = :u AND k.status = 'available' AND k.expires_at > now()
           ORDER BY at
              LIMIT 12",
            ['u' => $userId]
        );

        $attention = [
            'undated'     => $stats['undated'],
            'unkeyed'     => $one(
                "SELECT count(*) FROM stream_games sg JOIN streams s ON s.id = sg.stream_id
                  WHERE s.user_id = ? AND s.status = 'planned' AND sg.game_key_id IS NULL",
                [$userId]
            ),
            'placeholders' => $one(
                "SELECT count(*) FROM game_keys WHERE user_id = ? AND is_placeholder",
                [$userId]
            ),
            'vague_dates' => $one(
                "SELECT count(*) FROM games WHERE release_date IS NULL OR release_precision <> 'day'"
            ),
        ];

        View::render('dashboard', [
            'stats'      => $stats,
            'byStatus'   => $byStatus,
            'bySource'   => $bySource,
            'byPlatform' => $byPlatform,
            'byType'     => $byType,
            'coverage'   => $coverage,
            'agenda'     => $agenda,
            'attention'  => $attention,
        ], __('ui.nav.dashboard'));
    }
}
