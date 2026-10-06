<?php
declare(strict_types=1);

/**
 * A user's defaults for new content (see 031_content_length_prefixes_twitch_schedule.sql
 * and 036_title_counters.sql): how long sponsored content usually runs,
 * the title prefixes offered when writing a title (several may be
 * preselected, in order), and the counters those prefixes can use.
 */
final class ContentDefaults
{
    public const MIN_MINUTES = 15;
    public const MAX_MINUTES = 1440;
    public const MAX_PREFIXES = 20;
    public const PREFIX_MAX = 60;

    /** How long one piece of content usually runs, in minutes. */
    public static function minutes(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT content_minutes FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $minutes = $stmt->fetchColumn();

        return $minutes === false ? StreamSchedule::DEFAULT_MINUTES : (int) $minutes;
    }

    /** A length in minutes from a form field, or null when malformed or out of range. */
    public static function minutesFromInput(string $value): ?int
    {
        $value = trim($value);

        if (!ctype_digit($value)) {
            return null;
        }

        $minutes = (int) $value;

        return $minutes >= self::MIN_MINUTES && $minutes <= self::MAX_MINUTES ? $minutes : null;
    }

    /** @return list<array{id:int, prefix:string, is_default:bool}> in the user's order */
    public static function prefixes(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, prefix, is_default FROM user_title_prefixes WHERE user_id = ? ORDER BY position, id'
        );
        $stmt->execute([$userId]);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'prefix' => (string) $row['prefix'], 'is_default' => (bool) $row['is_default']],
            $stmt->fetchAll()
        );
    }

    /**
     * Reads the prefix rows of the defaults form: prefix[n][text] and
     * prefix[n][default]. Blank rows are dropped, repeats kept once.
     * Counters renamed in the same form are renamed here too, and every
     * {name} must be one of the counters.
     *
     * @param list<string> $counterNames
     * @param array<string, string> $renames old counter name => new
     * @return list<array{prefix:string, is_default:bool}>
     * @throws UserError naming what is wrong
     */
    public static function prefixesFromInput(array $rows, array $counterNames = [], array $renames = []): array
    {
        $prefixes = [];
        $seen     = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['text'] ?? '')));
            $text = TitleCounters::rename($text, $renames);

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > self::PREFIX_MAX) {
                throw new UserError(sprintf(__('ui.message.prefix_too_long'), $text, self::PREFIX_MAX));
            }

            $unknown = array_diff(TitleCounters::variables($text), $counterNames);

            if ($unknown !== []) {
                throw new UserError(sprintf(__('ui.message.prefix_unknown_counter'), $text, '{' . reset($unknown) . '}'));
            }

            if (isset($seen[mb_strtolower($text)])) {
                continue;
            }

            $seen[mb_strtolower($text)] = true;
            $prefixes[] = ['prefix' => $text, 'is_default' => !empty($row['default'])];
        }

        if (count($prefixes) > self::MAX_PREFIXES) {
            throw new UserError(sprintf(__('ui.message.prefixes_full'), self::MAX_PREFIXES));
        }

        return $prefixes;
    }

    /**
     * Saves the length, the counters and the prefixes.
     *
     * @param list<array{prefix:string, is_default:bool}> $prefixes
     * @param list<array{id:?int, name:string, value:int}> $counters
     */
    public static function save(int $userId, int $minutes, array $prefixes, array $counters = []): void
    {
        Database::transaction(static function (PDO $pdo) use ($userId, $minutes, $prefixes, $counters): void {
            $pdo->prepare('UPDATE users SET content_minutes = ? WHERE id = ?')->execute([$minutes, $userId]);
            TitleCounters::save($pdo, $userId, $counters);
            $pdo->prepare('DELETE FROM user_title_prefixes WHERE user_id = ?')->execute([$userId]);

            $insert = $pdo->prepare(
                'INSERT INTO user_title_prefixes (user_id, prefix, is_default, position) VALUES (?, ?, ?, ?)'
            );

            foreach ($prefixes as $position => $row) {
                $insert->execute([$userId, $row['prefix'], $row['is_default'] ? 'true' : 'false', $position]);
            }
        });
    }
}
