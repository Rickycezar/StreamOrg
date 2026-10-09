<?php
declare(strict_types=1);

/**
 * What users keep in StreamOrg, for administrators to look through
 * (Administration → User data): planned content, key vaults, collabs and
 * streamer lists, across every account, read-only.
 *
 * Key codes never leave the vault here: neither the code, its hash nor
 * the key's notes are read, only what a key is (game, platform, type,
 * status, dates). Each list is filtered by user, status and words, and
 * comes a page at a time.
 */
final class AdminData
{
    public const PER_PAGE = 100;

    public const SUBJECTS = ['content', 'keys', 'collabs', 'streamers'];

    /** Statuses each list can be filtered by (none for streamers). */
    public const STATUSES = [
        'content'   => ['planned', 'live', 'done', 'cancelled'],
        'keys'      => KeysController::STATUSES,
        'collabs'   => ['idea', 'proposed', 'agreed', 'scheduled', 'done', 'cancelled'],
        'streamers' => [],
    ];

    /**
     * Accounts with something in any of the lists, for the user filter.
     *
     * @return list<array{id:int, name:string, items:int}>
     */
    public static function users(): array
    {
        $rows = Database::connection()->query(
            "SELECT u.id, coalesce(nullif(u.display_name, ''), u.username) AS name,
                    (SELECT count(*) FROM streams WHERE user_id = u.id) + (SELECT count(*) FROM game_keys WHERE user_id = u.id)
                  + (SELECT count(*) FROM collabs WHERE user_id = u.id) + (SELECT count(*) FROM streamers WHERE user_id = u.id) AS items
               FROM users u ORDER BY lower(coalesce(nullif(u.display_name, ''), u.username))"
        )->fetchAll();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'items' => (int) $r['items']], $rows);
    }

    /**
     * One page of a list, with how many rows match in all.
     *
     * @param array{user?:?int, status?:string, q?:string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function page(string $subject, array $filters, int $page): array
    {
        [$select, $from, $search] = match ($subject) {
            'content'   => self::content(),
            'keys'      => self::keys(),
            'collabs'   => self::collabs(),
            'streamers' => self::streamers(),
            default     => throw new InvalidArgumentException('Unknown subject ' . $subject),
        };

        $alias  = ['content' => 's', 'keys' => 'k', 'collabs' => 'c', 'streamers' => 'st'][$subject];
        $where  = ['true'];
        $params = [];

        if (!empty($filters['user'])) {
            $where[]  = "{$alias}.user_id = ?";
            $params[] = (int) $filters['user'];
        }

        if (($filters['status'] ?? '') !== '' && in_array($filters['status'], self::STATUSES[$subject], true)) {
            $where[]  = "{$alias}.status = ?";
            $params[] = $filters['status'];
        }

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $where[] = '(' . implode(' OR ', array_map(static fn (string $col): string => "{$col} ILIKE ?", $search)) . ')';
            array_push($params, ...array_fill(0, count($search), '%' . addcslashes(mb_substr($q, 0, 80), '%_\\') . '%'));
        }

        $pdo  = Database::connection();
        $cond = implode(' AND ', $where);

        $count = $pdo->prepare("SELECT count(*) FROM {$from} WHERE {$cond}");
        $count->execute($params);

        $stmt = $pdo->prepare("SELECT {$select} FROM {$from} WHERE {$cond} ORDER BY {$alias}.created_at DESC, {$alias}.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (max(1, $page) - 1) * self::PER_PAGE);
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /**
     * How much of each subject there is, and how many accounts have some.
     *
     * @return array<string, array{items:int, users:int}>
     */
    public static function totals(): array
    {
        $out = [];

        foreach (['content' => 'streams', 'keys' => 'game_keys', 'collabs' => 'collabs', 'streamers' => 'streamers'] as $subject => $table) {
            $row = Database::connection()->query("SELECT count(*) AS items, count(DISTINCT user_id) AS users FROM {$table}")->fetch();
            $out[$subject] = ['items' => (int) $row['items'], 'users' => (int) $row['users']];
        }

        return $out;
    }

    /** @return array{0:string, 1:string, 2:list<string>} select, from, searched columns */
    private static function content(): array
    {
        return [
            "s.id, s.title, s.status, s.scheduled_start, s.deadline, s.planned_minutes, s.category_name, s.twitch_pushed_at,
             s.created_at, s.updated_at, s.collab_id IS NOT NULL AS is_collab, sp.code AS platform,
             u.id AS user_id, coalesce(nullif(u.display_name, ''), u.username) AS user_name,
             (SELECT string_agg(g.title, ', ' ORDER BY sg.play_order) FROM stream_games sg JOIN games g ON g.id = sg.game_id WHERE sg.stream_id = s.id) AS games,
             (SELECT count(*) FROM stream_games sg WHERE sg.stream_id = s.id AND sg.game_key_id IS NOT NULL) AS keys_linked",
            'streams s JOIN users u ON u.id = s.user_id LEFT JOIN streaming_platforms sp ON sp.id = s.streaming_platform_id',
            ['s.title', 's.category_name', "(SELECT string_agg(g.title, ' ') FROM stream_games sg JOIN games g ON g.id = sg.game_id WHERE sg.stream_id = s.id)"],
        ];
    }

    /** @return array{0:string, 1:string, 2:list<string>} key codes, hashes and notes are never selected */
    private static function keys(): array
    {
        return [
            "k.id, k.status, k.content_type, k.key_type, k.region, k.source_note, k.received_at, k.activated_at, k.expires_at,
             k.is_placeholder, k.created_at, g.title AS game, kp.code AS key_platform, gp.code AS game_platform,
             u.id AS user_id, coalesce(nullif(u.display_name, ''), u.username) AS user_name,
             EXISTS (SELECT 1 FROM stream_games sg WHERE sg.game_key_id = k.id) AS in_content",
            'game_keys k JOIN users u ON u.id = k.user_id JOIN games g ON g.id = k.game_id
             LEFT JOIN key_platforms kp ON kp.id = k.key_platform_id LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id',
            ['g.title', 'k.source_note', 'k.region'],
        ];
    }

    /** @return array{0:string, 1:string, 2:list<string>} */
    private static function collabs(): array
    {
        return [
            "c.id, c.title, c.status, c.proposed_for, c.created_at, sp.code AS platform,
             u.id AS user_id, coalesce(nullif(u.display_name, ''), u.username) AS user_name,
             (SELECT string_agg(st.name, ', ' ORDER BY lower(st.name)) FROM collab_streamers cs JOIN streamers st ON st.id = cs.streamer_id WHERE cs.collab_id = c.id) AS cast_names,
             (SELECT count(*) FROM streams s WHERE s.collab_id = c.id) AS content_count,
             EXISTS (SELECT 1 FROM collab_sessions cx WHERE cx.collab_id = c.id) AS together",
            'collabs c JOIN users u ON u.id = c.user_id LEFT JOIN streaming_platforms sp ON sp.id = c.streaming_platform_id',
            ['c.title', "(SELECT string_agg(st.name, ' ') FROM collab_streamers cs JOIN streamers st ON st.id = cs.streamer_id WHERE cs.collab_id = c.id)"],
        ];
    }

    /** @return array{0:string, 1:string, 2:list<string>} */
    private static function streamers(): array
    {
        return [
            "st.id, st.name, st.is_favorite, st.broadcaster_type, st.created_at,
             u.id AS user_id, coalesce(nullif(u.display_name, ''), u.username) AS user_name,
             (SELECT string_agg(coalesce(p.code, '?') || ': ' || ch.handle, ', ' ORDER BY ch.is_primary DESC) FROM streamer_channels ch
                LEFT JOIN streaming_platforms p ON p.id = ch.streaming_platform_id WHERE ch.streamer_id = st.id) AS channels,
             EXISTS (SELECT 1 FROM streamer_channels ch JOIN twitch_connections tc ON lower(tc.twitch_login) = lower(ch.handle)
                      WHERE ch.streamer_id = st.id) AS on_streamorg,
             (SELECT count(*) FROM collab_streamers cs WHERE cs.streamer_id = st.id) AS collab_count",
            'streamers st JOIN users u ON u.id = st.user_id',
            ['st.name', "(SELECT string_agg(ch.handle, ' ') FROM streamer_channels ch WHERE ch.streamer_id = st.id)"],
        ];
    }
}
