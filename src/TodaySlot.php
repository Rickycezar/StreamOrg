<?php
declare(strict_types=1);

/**
 * Finds where an undated plan fits today: the first gap long enough for
 * it, from the user's usual start time (or from now, once that has
 * passed), between what is already planned for the day.
 */
final class TodaySlot
{
    /** Times are rounded up to this many minutes. */
    private const STEP = 15;

    /**
     * The start for content of $minutes today, or null when the day has no
     * room left for it.
     */
    public static function find(PDO $pdo, int $userId, int $minutes, ?int $skipId = null, ?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $zone  = new DateTimeZone(date_default_timezone_get());
        $now   = ($now ?? new DateTimeImmutable())->setTimezone($zone);
        $today = $now->setTime(0, 0);

        [$hour, $minute] = array_map('intval', explode(':', StreamSchedule::startOn(StreamSchedule::forUser($userId), $today)));
        $from = max($today->setTime($hour, $minute), self::roundUp($now));

        $stmt = $pdo->prepare(
            "SELECT scheduled_start, ended_at, planned_minutes FROM streams
              WHERE user_id = ? AND status <> 'cancelled' AND id IS DISTINCT FROM ?
                AND scheduled_start >= ? AND scheduled_start < ?
           ORDER BY scheduled_start"
        );
        $stmt->execute([$userId, $skipId, $today->format(DATE_ATOM), $today->modify('+1 day')->format(DATE_ATOM)]);

        $usual = ContentDefaults::minutes($userId);
        $busy  = array_map(static function (array $row) use ($usual, $zone): array {
            $start = (new DateTimeImmutable($row['scheduled_start']))->setTimezone($zone);
            $end   = $row['ended_at'] !== null
                ? (new DateTimeImmutable($row['ended_at']))->setTimezone($zone)
                : $start->modify('+' . (int) ($row['planned_minutes'] ?? $usual) . ' minutes');

            return [$start, max($start, $end)];
        }, $stmt->fetchAll());

        return self::firstGap($from, $busy, $minutes, $today->modify('+1 day'));
    }

    /**
     * The first start at or after $from where $minutes fit between the busy
     * spans (each [start, end], by start), ending before $dayEnd.
     *
     * @param list<array{0:DateTimeImmutable, 1:DateTimeImmutable}> $busy
     */
    public static function firstGap(DateTimeImmutable $from, array $busy, int $minutes, DateTimeImmutable $dayEnd): ?DateTimeImmutable
    {
        $at = $from;

        foreach ($busy as [$start, $end]) {
            if ($at->modify('+' . $minutes . ' minutes') <= $start) {
                break;
            }

            if ($end > $at) {
                $at = self::roundUp($end);
            }
        }

        return $at < $dayEnd ? $at : null;
    }

    private static function roundUp(DateTimeImmutable $at): DateTimeImmutable
    {
        $minutes = (int) $at->format('G') * 60 + (int) $at->format('i') + ((int) $at->format('s') > 0 ? 1 : 0);
        $rounded = (int) ceil($minutes / self::STEP) * self::STEP;

        return $at->setTime(0, 0)->modify('+' . $rounded . ' minutes');
    }
}
