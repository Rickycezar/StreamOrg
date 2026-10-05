<?php
declare(strict_types=1);

/**
 * A user's usual stream days and hours (see 022_user_stream_schedule.sql).
 *
 * Times are wall-clock "HH:MM" strings in the user's own time zone; days
 * are ISO weekdays, 1 = Monday ... 7 = Sunday. A day missing from the
 * schedule is a day off.
 */
final class StreamSchedule
{
    /** Start time used when the user has no schedule for a day. */
    public const FALLBACK_START = '20:00';

    /** How long content runs when the user has not said (see ContentDefaults). */
    public const DEFAULT_MINUTES = 120;

    /** @return array<int, array{start: string, end: ?string}> weekday => times, Monday first */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT weekday, to_char(starts_at, 'HH24:MI') AS start, to_char(ends_at, 'HH24:MI') AS \"end\"
               FROM user_stream_schedule WHERE user_id = ? ORDER BY weekday"
        );
        $stmt->execute([$userId]);

        $days = [];

        foreach ($stmt->fetchAll() as $row) {
            $days[(int) $row['weekday']] = ['start' => $row['start'], 'end' => $row['end']];
        }

        return $days;
    }

    /**
     * Replaces the whole schedule.
     *
     * @param array<int, array{start: string, end: ?string}> $days
     */
    public static function save(int $userId, array $days): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        $pdo->prepare('DELETE FROM user_stream_schedule WHERE user_id = ?')->execute([$userId]);

        $insert = $pdo->prepare(
            'INSERT INTO user_stream_schedule (user_id, weekday, starts_at, ends_at) VALUES (?, ?, ?, ?)'
        );

        foreach ($days as $weekday => $times) {
            $insert->execute([$userId, $weekday, $times['start'], $times['end']]);
        }

        $pdo->commit();
    }

    /**
     * Reads the profile form: day[n][on], day[n][start], day[n][end].
     * Returns null when a ticked day has no valid start time or a given
     * end time is malformed.
     *
     * @return ?array<int, array{start: string, end: ?string}>
     */
    public static function fromInput(array $input): ?array
    {
        $days = [];

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $day = $input[$weekday] ?? [];

            if (!is_array($day) || empty($day['on'])) {
                continue;
            }

            $start = trim((string) ($day['start'] ?? ''));
            $end   = trim((string) ($day['end'] ?? ''));

            if (!self::isTime($start) || ($end !== '' && !self::isTime($end))) {
                return null;
            }

            $days[$weekday] = ['start' => $start, 'end' => $end === '' || $end === $start ? null : $end];
        }

        return $days;
    }

    /** The start time for a given date: the schedule's, or the fallback. */
    public static function startOn(array $schedule, DateTimeInterface $date): string
    {
        return $schedule[(int) $date->format('N')]['start'] ?? self::FALLBACK_START;
    }

    private static function isTime(string $value): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }
}
