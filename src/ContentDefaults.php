<?php
declare(strict_types=1);

/**
 * A user's defaults for new content (see 031_content_length_prefixes_twitch_schedule.sql
 * and 036_title_counters.sql): how long sponsored content usually runs,
 * the title prefixes and suffixes offered when writing a title (several
 * may be preselected, in order), and the counters they can use.
 */
final class ContentDefaults
{
    public const MIN_MINUTES = 15;
    public const MAX_MINUTES = 1440;
    public const MAX_PREFIXES = 20;
    public const PREFIX_MAX = 60;
    public const COLLAB_PREFIX_MAX = 20;

    /** How long one piece of content usually runs, in minutes. */
    public static function minutes(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT content_minutes FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $minutes = $stmt->fetchColumn();

        return $minutes === false ? StreamSchedule::DEFAULT_MINUTES : (int) $minutes;
    }

    /** The word before collab guests in a title ("ft." unless the user chose another, or none). */
    public static function collabPrefix(int $userId): string
    {
        $stmt = Database::connection()->prepare('SELECT collab_prefix FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $prefix = $stmt->fetchColumn();

        return $prefix === false ? 'ft.' : (string) $prefix;
    }

    /**
     * Reads the collab credit field: up to 20 characters, no @ or #.
     *
     * @throws UserError
     */
    public static function collabPrefixFromInput(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (mb_strlen($value) > self::COLLAB_PREFIX_MAX || preg_match('/[@#]/', $value)) {
            throw new UserError(sprintf(__('ui.message.collab_prefix_invalid'), self::COLLAB_PREFIX_MAX));
        }

        return $value;
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

    public const KINDS = ['prefix', 'suffix'];

    /**
     * The user's prefixes and suffixes, in their order.
     *
     * @return list<array{id:int, prefix:string, is_default:bool, kind:string}>
     */
    public static function prefixes(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, prefix, is_default, kind FROM user_title_prefixes WHERE user_id = ? ORDER BY position, id'
        );
        $stmt->execute([$userId]);

        return array_map(
            static fn (array $row): array => [
                'id'         => (int) $row['id'],
                'prefix'     => (string) $row['prefix'],
                'is_default' => (bool) $row['is_default'],
                'kind'       => (string) $row['kind'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * A title with the default prefixes before the text and the default
     * suffixes after it.
     *
     * @param list<array{prefix:string, is_default:bool, kind?:string}> $items
     */
    public static function withDefaults(array $items, string $body = ''): string
    {
        $pick = static fn (string $kind): string => implode(' ', array_map(
            static fn (array $p): string => $p['prefix'],
            array_filter($items, static fn (array $p): bool => $p['is_default'] && ($p['kind'] ?? 'prefix') === $kind)
        ));

        return trim(implode(' ', array_filter([$pick('prefix'), trim($body), $pick('suffix')], static fn (string $part): bool => $part !== '')));
    }

    /** The default suffixes alone, as written after a new title's text. */
    public static function defaultTail(array $items): string
    {
        return self::withDefaults(array_values(array_filter($items, static fn (array $p): bool => ($p['kind'] ?? 'prefix') === 'suffix')));
    }

    /**
     * A new title before its text is written: the default prefixes, room
     * for the text, and the default suffixes.
     *
     * @return array{value:string, caret:int} the title and where the text goes
     */
    public static function newTitle(array $items): array
    {
        $lead = self::defaultLead($items);
        $tail = self::defaultTail($items);

        return ['value' => $lead . ($tail !== '' ? ' ' . $tail : ''), 'caret' => mb_strlen($lead)];
    }

    /** The default prefixes alone, as written before a new title's text (with its trailing space). */
    public static function defaultLead(array $items): string
    {
        $lead = self::withDefaults(array_values(array_filter($items, static fn (array $p): bool => ($p['kind'] ?? 'prefix') === 'prefix')));

        return $lead === '' ? '' : $lead . ' ';
    }

    /**
     * Reads the prefix rows of the defaults form: prefix[n][text],
     * prefix[n][default] and prefix[n][kind] (prefix or suffix). Blank
     * rows are dropped, repeats kept once.
     * Counters renamed in the same form are renamed here too, and every
     * {name} must be one of the counters.
     *
     * @param list<string> $counterNames
     * @param array<string, string> $renames old counter name => new
     * @return list<array{prefix:string, is_default:bool, kind:string}>
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
            $prefixes[] = [
                'prefix'     => $text,
                'is_default' => !empty($row['default']),
                'kind'       => in_array($row['kind'] ?? '', self::KINDS, true) ? (string) $row['kind'] : 'prefix',
            ];
        }

        if (count($prefixes) > self::MAX_PREFIXES) {
            throw new UserError(sprintf(__('ui.message.prefixes_full'), self::MAX_PREFIXES));
        }

        return $prefixes;
    }

    /**
     * Saves the length, the collab credit, the counters and the prefixes
     * and suffixes.
     *
     * @param list<array{prefix:string, is_default:bool, kind?:string}> $prefixes
     * @param list<array{id:?int, name:string, value:int}> $counters
     * @param array<string, string> $renames old counter name => new
     * @throws UserError when a counter still used by content would be deleted
     */
    public static function save(int $userId, int $minutes, array $prefixes, array $counters = [], array $renames = [], ?string $collabPrefix = null): void
    {
        Database::transaction(static function (PDO $pdo) use ($userId, $minutes, $prefixes, $counters, $renames, $collabPrefix): void {
            $pdo->prepare('UPDATE users SET content_minutes = ?, collab_prefix = coalesce(?, collab_prefix) WHERE id = ?')
                ->execute([$minutes, $collabPrefix, $userId]);
            TitleCounters::save($pdo, $userId, $counters, $renames);
            $pdo->prepare('DELETE FROM user_title_prefixes WHERE user_id = ?')->execute([$userId]);

            $insert = $pdo->prepare(
                'INSERT INTO user_title_prefixes (user_id, prefix, is_default, position, kind) VALUES (?, ?, ?, ?, ?)'
            );

            foreach ($prefixes as $position => $row) {
                $insert->execute([$userId, $row['prefix'], $row['is_default'] ? 'true' : 'false', $position, $row['kind'] ?? 'prefix']);
            }
        });
    }
}
