<?php
declare(strict_types=1);

/**
 * The numbers on Administration → Overview: what needs an administrator
 * (open errors, bug reports and feedback with news, the chat bot's state,
 * open surveys), how StreamOrg is being used (accounts, recent activity,
 * planned content, keys, giveaways, overlays, live channels, media), the
 * newest accounts and the latest things people added.
 */
final class AdminOverview
{
    /** @return array<string, int|string> */
    public static function attention(): array
    {
        $pdo    = Database::connection();
        $errors = ErrorLog::openCounts();
        $bugs   = BugReports::counts();
        $bot    = ChatBot::account();

        return [
            'errors'   => ($errors['error'] ?? 0) + ($errors['fatal'] ?? 0) + ($errors['critical'] ?? 0),
            'warnings' => array_sum($errors) - (($errors['error'] ?? 0) + ($errors['fatal'] ?? 0) + ($errors['critical'] ?? 0)),
            'bugs_new' => (int) ($bugs['new'] ?? 0),
            'bugs_news' => (int) ($bugs['unread'] ?? 0),
            'feedback' => (int) (Feedback::boxCounts()['unread'] ?? 0),
            'surveys'  => (int) $pdo->query("SELECT count(*) FROM surveys WHERE status = 'open'")->fetchColumn(),
            'bot'      => empty($bot['twitch_login']) ? 'none' : (!$bot['is_enabled'] ? 'off' : (!empty($bot['online']) ? (string) ($bot['state'] ?: 'connected') : 'offline')),
        ];
    }

    /** @return array<string, int|float> */
    public static function activity(): array
    {
        $row = Database::connection()->query(
            "SELECT (SELECT count(*) FROM users) AS accounts,
                    (SELECT count(DISTINCT user_id) FROM user_sessions WHERE last_seen_at > now() - interval '7 days') AS active_week,
                    (SELECT count(*) FROM users WHERE created_at > now() - interval '30 days') AS new_month,
                    (SELECT count(*) FROM streams WHERE status = 'planned' AND scheduled_start BETWEEN now() AND now() + interval '7 days') AS content_week,
                    (SELECT count(*) FROM twitch_broadcasts WHERE ended_at IS NULL) AS live_now,
                    (SELECT count(*) FROM game_keys) AS keys,
                    (SELECT count(*) FROM game_keys WHERE status = 'available') AS keys_available,
                    (SELECT count(*) FROM giveaways WHERE status = 'open') AS giveaways_open,
                    (SELECT count(*) FROM collabs WHERE status NOT IN ('done', 'cancelled')) AS collabs_open,
                    (SELECT count(*) FROM overlays) AS overlays,
                    (SELECT count(*) FROM overlays WHERE last_seen_at > now() - interval '10 minutes') AS overlays_open,
                    (SELECT coalesce(sum(bytes), 0) FROM media_assets) AS media_bytes,
                    (SELECT count(*) FROM bot_channels WHERE is_enabled AND NOT is_blocked) AS bot_channels,
                    (SELECT count(*) FROM games) AS games,
                    (SELECT count(*) FROM publishers) AS publishers,
                    (SELECT count(*) FROM developers) AS developers,
                    (SELECT count(*) FROM key_platforms) AS key_sites,
                    (SELECT count(*) FROM viewers) AS viewers"
        )->fetch();

        return array_map('intval', $row);
    }

    /**
     * The newest accounts, with when they last used StreamOrg.
     *
     * @return list<array<string, mixed>>
     */
    public static function newestAccounts(int $limit = 6): array
    {
        return Database::connection()->query(
            "SELECT u.id, coalesce(nullif(u.display_name, ''), u.username) AS name, u.role, u.created_at,
                    (SELECT max(last_seen_at) FROM user_sessions s WHERE s.user_id = u.id) AS last_seen,
                    t.twitch_login
               FROM users u LEFT JOIN twitch_connections t ON t.user_id = u.id
              ORDER BY u.created_at DESC, u.id DESC LIMIT " . max(1, $limit)
        )->fetchAll();
    }

    /**
     * The latest things people added: content, keys (the game only; keys
     * added together as one line, with how many), collabs and streamers,
     * newest first.
     *
     * @return list<array{kind:string, label:string, user_id:int, user_name:string, created_at:string, items:int}>
     */
    public static function latest(int $limit = 8): array
    {
        $rows = Database::connection()->query(
            "SELECT * FROM (
                SELECT 'content' AS kind, s.title AS label, s.user_id, s.created_at, 1 AS items FROM streams s
                UNION ALL SELECT 'keys', g.title, k.user_id, max(k.created_at), count(*) FROM game_keys k JOIN games g ON g.id = k.game_id
                          GROUP BY g.title, k.user_id, date_trunc('minute', k.created_at)
                UNION ALL SELECT 'collabs', c.title, c.user_id, c.created_at, 1 FROM collabs c
                UNION ALL SELECT 'streamers', st.name, st.user_id, st.created_at, 1 FROM streamers st
             ) x JOIN LATERAL (SELECT coalesce(nullif(display_name, ''), username) AS user_name FROM users WHERE id = x.user_id) u ON true
             ORDER BY created_at DESC LIMIT " . max(1, $limit)
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'kind'       => (string) $r['kind'],
            'label'      => (string) $r['label'],
            'user_id'    => (int) $r['user_id'],
            'user_name'  => (string) $r['user_name'],
            'created_at' => (string) $r['created_at'],
            'items'      => (int) $r['items'],
        ], $rows);
    }
}
