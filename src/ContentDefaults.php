<?php
declare(strict_types=1);

/**
 * A user's defaults for new content (see 031_content_length_prefixes_twitch_schedule.sql):
 * how long sponsored content usually runs, and the title prefixes offered
 * when writing a title, one of them preselected.
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

    /** @return list<array{prefix:string, is_default:bool}> in the user's order */
    public static function prefixes(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT prefix, is_default FROM user_title_prefixes WHERE user_id = ? ORDER BY position, id'
        );
        $stmt->execute([$userId]);

        return array_map(
            static fn (array $row): array => ['prefix' => (string) $row['prefix'], 'is_default' => (bool) $row['is_default']],
            $stmt->fetchAll()
        );
    }

    /**
     * Reads the defaults form: prefix[n][text] rows and the "default" radio
     * naming one row. Blank rows are dropped, repeats kept once.
     *
     * @return list<array{prefix:string, is_default:bool}>|null null when a prefix is too long or there are too many
     */
    public static function prefixesFromInput(array $rows, string $default): ?array
    {
        $prefixes = [];
        $seen     = [];

        foreach ($rows as $key => $row) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) (is_array($row) ? ($row['text'] ?? '') : '')));

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > self::PREFIX_MAX) {
                return null;
            }

            if (isset($seen[mb_strtolower($text)])) {
                continue;
            }

            $seen[mb_strtolower($text)] = true;
            $prefixes[] = ['prefix' => $text, 'is_default' => (string) $key === $default];
        }

        return count($prefixes) > self::MAX_PREFIXES ? null : $prefixes;
    }

    /**
     * Saves the length and replaces the prefixes.
     *
     * @param list<array{prefix:string, is_default:bool}> $prefixes
     */
    public static function save(int $userId, int $minutes, array $prefixes): void
    {
        Database::transaction(static function (PDO $pdo) use ($userId, $minutes, $prefixes): void {
            $pdo->prepare('UPDATE users SET content_minutes = ? WHERE id = ?')->execute([$minutes, $userId]);
            $pdo->prepare('DELETE FROM user_title_prefixes WHERE user_id = ?')->execute([$userId]);

            $insert = $pdo->prepare(
                'INSERT INTO user_title_prefixes (user_id, prefix, is_default, position) VALUES (?, ?, ?, ?)'
            );
            $hasDefault = false;

            foreach ($prefixes as $position => $row) {
                $isDefault  = $row['is_default'] && !$hasDefault;
                $hasDefault = $hasDefault || $isDefault;
                $insert->execute([$userId, $row['prefix'], $isDefault ? 'true' : 'false', $position]);
            }
        });
    }
}
