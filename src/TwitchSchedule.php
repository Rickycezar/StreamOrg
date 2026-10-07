<?php
declare(strict_types=1);

/**
 * Sends planned content to the streamer's Twitch schedule (the Schedule
 * tab on their channel page), as one-off segments.
 *
 * Every planned, dated Twitch content still ahead becomes a segment with
 * its title, start, length (its own, or the usual content length) and
 * Twitch category. Segments StreamOrg created are remembered in
 * twitch_schedule_segments: a later send updates the ones whose content
 * changed and removes the ones whose content was unscheduled, cancelled,
 * moved to another platform or deleted. Segments the streamer made on
 * Twitch themselves, recurring ones included, are never touched; neither
 * are segments of content that already happened.
 *
 * Twitch only offers a schedule to affiliates and partners, and needs the
 * channel:manage:schedule scope, which connections made before this
 * feature lack: they are asked to reconnect.
 */
final class TwitchSchedule
{
    /** Twitch's limits for a segment. */
    public const MIN_MINUTES = 30;
    public const MAX_MINUTES = 1380;
    public const TITLE_MAX   = 140;

    /** At most this many segments are sent at once. */
    public const LIMIT = 50;

    /**
     * @return array{state:'disconnected'|'reconnect'|'ready', synced:int, last:?string}
     */
    public static function status(int $userId): array
    {
        if (TwitchUser::connection($userId) === null) {
            return ['state' => 'disconnected', 'synced' => 0, 'last' => null];
        }

        $stmt = Database::connection()->prepare(
            'SELECT count(*) FILTER (WHERE stream_id IS NOT NULL) AS synced, max(synced_at) AS last
               FROM twitch_schedule_segments WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return [
            'state'  => TwitchUser::hasScope($userId, TwitchUser::SCHEDULE_SCOPE) ? 'ready' : 'reconnect',
            'synced' => (int) $row['synced'],
            'last'   => $row['last'],
        ];
    }

    /** One line for the planner: when the schedule was last sent, or what is missing. */
    public static function describe(array $status): string
    {
        return match (true) {
            $status['state'] === 'reconnect' => __('ui.message.twitch_schedule_reconnect'),
            $status['last'] !== null         => sprintf(__('ui.message.twitch_schedule_last'), fmt_datetime($status['last']), $status['synced']),
            default                          => __('ui.message.twitch_schedule_never'),
        };
    }

    /**
     * Brings the Twitch schedule in line with the planned content.
     *
     * @return array{created:int, updated:int, removed:int, unchanged:int, failed:int, error:?string}
     * @throws UserError when the channel cannot be reached at all
     */
    public static function send(int $userId): array
    {
        $status = self::status($userId);

        if ($status['state'] === 'disconnected') {
            throw new UserError(__('ui.message.twitch_not_connected'));
        }

        if ($status['state'] === 'reconnect') {
            throw new UserError(__('ui.message.twitch_schedule_reconnect'));
        }

        $pdo    = Database::connection();
        $result = ['created' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0, 'failed' => 0, 'error' => null];
        $known  = self::known($pdo, $userId);
        $wanted = [];

        foreach (self::planned($pdo, $userId) as $content) {
            $streamId          = (int) $content['id'];
            $wanted[$streamId] = true;
            $segment           = self::segment($content);
            $fingerprint       = hash('sha256', (string) json_encode($segment));
            $existing          = $known[$streamId] ?? null;

            if ($existing !== null && hash_equals($existing['fingerprint'], $fingerprint)) {
                $result['unchanged']++;
                continue;
            }

            if ($existing !== null) {
                $response = TwitchUser::scheduleSegment($userId, 'PATCH', $existing['segment_id'], $segment);

                if ($response !== null && $response['status'] === 200) {
                    self::remember($pdo, $userId, $existing['segment_id'], $streamId, $fingerprint);
                    $result['updated']++;
                    continue;
                }

                if ($response === null || $response['status'] !== 404) {
                    self::failed($result, $response);
                    continue;
                }

                self::forget($pdo, $userId, $existing['segment_id']);
            }

            $response = TwitchUser::scheduleSegment($userId, 'POST', null, $segment + ['is_recurring' => false]);
            $id       = $response !== null && $response['status'] === 200 ? self::createdId($response, $segment) : null;

            if (!is_string($id) || $id === '') {
                self::failed($result, $response);
                continue;
            }

            self::remember($pdo, $userId, $id, $streamId, $fingerprint);
            $result['created']++;
        }

        foreach (self::stale($pdo, $userId, $wanted) as $row) {
            if ($row['happened']) {
                self::forget($pdo, $userId, $row['segment_id']);
                continue;
            }

            $response = TwitchUser::scheduleSegment($userId, 'DELETE', $row['segment_id']);

            if ($response !== null && in_array($response['status'], [204, 404], true)) {
                self::forget($pdo, $userId, $row['segment_id']);
                $result['removed']++;
            } else {
                self::failed($result, $response);
            }
        }

        return $result;
    }

    /**
     * What Twitch is sent for one content item.
     *
     * @return array{start_time:string, timezone:string, duration:string, title:string, category_id?:string}
     */
    public static function segment(array $content): array
    {
        $minutes = max(self::MIN_MINUTES, min(self::MAX_MINUTES, (int) $content['minutes']));
        $title   = trim((string) preg_replace('/\s+/u', ' ', (string) $content['title']));

        $segment = [
            'start_time' => (new DateTimeImmutable((string) $content['scheduled_start']))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'timezone'   => date_default_timezone_get(),
            'duration'   => (string) $minutes,
            'title'      => mb_substr($title, 0, self::TITLE_MAX),
        ];

        if (!empty($content['category_id'])) {
            $segment['category_id'] = (string) $content['category_id'];
        }

        return $segment;
    }

    /**
     * The id of the segment just created: Twitch answers with the whole
     * schedule, so it is the one starting when and named as sent.
     */
    public static function createdId(array $response, array $segment): ?string
    {
        $segments = (array) (Http::json($response)['data']['segments'] ?? []);
        $start    = strtotime($segment['start_time']);

        foreach ($segments as $row) {
            if (is_array($row) && isset($row['id'], $row['start_time'])
                && strtotime((string) $row['start_time']) === $start
                && (string) ($row['title'] ?? '') === $segment['title']) {
                return (string) $row['id'];
            }
        }

        return count($segments) === 1 && isset($segments[0]['id']) ? (string) $segments[0]['id'] : null;
    }

    /** @return list<array<string,mixed>> planned Twitch content still ahead, soonest first */
    private static function planned(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT s.id, s.title, s.scheduled_start,
                    coalesce(s.planned_minutes, u.content_minutes) AS minutes,
                    coalesce(s.category_id, uc.category_id, g.twitch_category_id) AS category_id
               FROM streams s
               JOIN users u               ON u.id = s.user_id
               JOIN streaming_platforms sp ON sp.id = s.streaming_platform_id AND sp.code = 'twitch'
          LEFT JOIN LATERAL (
                  SELECT g.* FROM stream_games sg JOIN games g ON g.id = sg.game_id
                   WHERE sg.stream_id = s.id
                ORDER BY sg.play_order, g.id LIMIT 1
              ) g ON true
          LEFT JOIN user_twitch_categories uc ON uc.user_id = s.user_id AND uc.game_id = g.id
              WHERE s.user_id = ? AND s.status = 'planned' AND s.scheduled_start > now()
           ORDER BY s.scheduled_start
              LIMIT " . self::LIMIT
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /** @return array<int, array{segment_id:string, fingerprint:string}> by content id */
    private static function known(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT stream_id, segment_id, fingerprint FROM twitch_schedule_segments WHERE user_id = ? AND stream_id IS NOT NULL'
        );
        $stmt->execute([$userId]);

        $known = [];

        foreach ($stmt->fetchAll() as $row) {
            $known[(int) $row['stream_id']] = ['segment_id' => (string) $row['segment_id'], 'fingerprint' => (string) $row['fingerprint']];
        }

        return $known;
    }

    /**
     * Segments whose content is no longer planned ahead on Twitch, and
     * whether that content already happened (kept on Twitch, as history).
     *
     * @param array<int, true> $wanted
     * @return list<array{segment_id:string, happened:bool}>
     */
    private static function stale(PDO $pdo, int $userId, array $wanted): array
    {
        $stmt = $pdo->prepare(
            "SELECT t.segment_id, t.stream_id,
                    coalesce(s.status IN ('live', 'done') OR s.scheduled_start <= now(), false) AS happened
               FROM twitch_schedule_segments t
          LEFT JOIN streams s ON s.id = t.stream_id
              WHERE t.user_id = ?"
        );
        $stmt->execute([$userId]);

        $stale = [];

        foreach ($stmt->fetchAll() as $row) {
            if ($row['stream_id'] !== null && isset($wanted[(int) $row['stream_id']])) {
                continue;
            }

            $stale[] = ['segment_id' => (string) $row['segment_id'], 'happened' => (bool) $row['happened']];
        }

        return $stale;
    }

    private static function remember(PDO $pdo, int $userId, string $segmentId, int $streamId, string $fingerprint): void
    {
        $pdo->prepare(
            'INSERT INTO twitch_schedule_segments (user_id, segment_id, stream_id, fingerprint)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, segment_id) DO UPDATE
                SET stream_id = EXCLUDED.stream_id, fingerprint = EXCLUDED.fingerprint, synced_at = now()'
        )->execute([$userId, $segmentId, $streamId, $fingerprint]);
    }

    private static function forget(PDO $pdo, int $userId, string $segmentId): void
    {
        $pdo->prepare('DELETE FROM twitch_schedule_segments WHERE user_id = ? AND segment_id = ?')->execute([$userId, $segmentId]);
    }

    /** Counts a failure, keeping Twitch's first explanation for the user. */
    private static function failed(array &$result, ?array $response): void
    {
        $result['failed']++;

        if ($result['error'] === null) {
            $message         = $response !== null ? (Http::json($response)['message'] ?? null) : null;
            $result['error'] = is_string($message) && $message !== '' ? $message : TwitchUser::lastError();

            ErrorLog::note('Twitch schedule: ' . ($response !== null ? Twitch::describe($response, 'helix/schedule/segment') : (string) TwitchUser::lastError()));
        }
    }
}
