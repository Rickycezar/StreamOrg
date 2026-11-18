<?php
declare(strict_types=1);

/**
 * Counters for title prefixes (see 036_title_counters.sql and
 * 037_timeline_title_numbers.sql): a prefix such as "[STREAM #{stream}]"
 * names a counter in braces.
 *
 * A title that uses a counter is kept as a template (streams.title_template,
 * see 038_title_templates.sql), and its number is its place in time: a counter's value is where numbering starts after, and each dated,
 * not cancelled content using it counts up from there, earliest first.
 * Undated content shows "?". A counter set to count days (per_day, see
 * 063_counter_per_day.sql) gives every content on the same day, in the
 * creator's time zone, the same number. The database works the titles out
 * again whenever content or counters change (streamorg_renumber_titles),
 * so the order stays chronological however a date moved.
 */
final class TitleCounters
{
    public const MAX = 20;
    public const VALUE_MAX = 999999;
    public const NAME = '[a-z][a-z0-9_]{0,19}';

    /** @return list<array{id:int, name:string, value:int, per_day:bool}> */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT id, name, value, per_day FROM user_counters WHERE user_id = ? ORDER BY name');
        $stmt->execute([$userId]);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'value' => (int) $row['value'], 'per_day' => (bool) $row['per_day']],
            $stmt->fetchAll()
        );
    }

    /** The counters a prefix uses: "{stream} day {control}" -> ['stream', 'control']. */
    public static function variables(string $text): array
    {
        preg_match_all('/\{(' . self::NAME . ')\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** Fills in counters: unknown names stay as written. */
    public static function render(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{(' . self::NAME . ')\}/',
            static fn (array $m): string => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0],
            $text
        );
    }

    /**
     * Reads the counter rows of the defaults form:
     * counter[n][id|name|value|per_day]. Blank names are dropped.
     *
     * @return list<array{id:?int, name:string, value:int, per_day:bool}>
     * @throws UserError naming what is wrong
     */
    public static function fromInput(array $rows): array
    {
        $counters = [];
        $seen     = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name  = strtolower(trim((string) ($row['name'] ?? '')));
            $value = trim((string) ($row['value'] ?? '0'));

            if ($name === '') {
                continue;
            }

            if (!preg_match('/^' . self::NAME . '$/', $name)) {
                throw new UserError(sprintf(__('ui.message.counter_name_invalid'), $name));
            }

            if (!ctype_digit($value === '' ? '0' : $value) || (int) $value > self::VALUE_MAX) {
                throw new UserError(sprintf(__('ui.message.counter_value_invalid'), $name, self::VALUE_MAX));
            }

            if (isset($seen[$name])) {
                throw new UserError(sprintf(__('ui.message.counter_name_taken'), $name));
            }

            $seen[$name] = true;
            $id          = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);
            $counters[]  = ['id' => $id ?: null, 'name' => $name, 'value' => (int) $value, 'per_day' => !empty($row['per_day'])];
        }

        if (count($counters) > self::MAX) {
            throw new UserError(sprintf(__('ui.message.counters_full'), self::MAX));
        }

        return $counters;
    }

    /**
     * Old name => new name for counters the form renamed, so the prefixes
     * that used them can follow.
     *
     * @param list<array{id:int, name:string}> $existing
     * @param list<array{id:?int, name:string}> $posted
     * @return array<string, string>
     */
    public static function renames(array $existing, array $posted): array
    {
        $byId    = array_column($existing, 'name', 'id');
        $renames = [];

        foreach ($posted as $counter) {
            $old = $counter['id'] !== null ? ($byId[$counter['id']] ?? null) : null;

            if ($old !== null && $old !== $counter['name']) {
                $renames[$old] = $counter['name'];
            }
        }

        return $renames;
    }

    /** Applies renames to a prefix text: "{old}" becomes "{new}". */
    public static function rename(string $text, array $renames): string
    {
        return (string) preg_replace_callback(
            '/\{(' . self::NAME . ')\}/',
            static fn (array $m): string => '{' . ($renames[$m[1]] ?? $m[1]) . '}',
            $text
        );
    }

    /**
     * Writes the user's counters as posted: updates kept ones (by id),
     * adds new ones and deletes the rest. Kept ones pass through a
     * temporary name first, so two counters can swap names. Renamed
     * counters are renamed in the content that uses them too.
     *
     * @param list<array{id:?int, name:string, value:int, per_day:bool}> $counters
     * @param array<string, string> $renames old name => new
     * @throws UserError when a counter to delete is still used by content
     */
    public static function save(PDO $pdo, int $userId, array $counters, array $renames = []): void
    {
        $existing = array_column(self::forUser($userId), 'name', 'id');
        $kept     = array_values(array_filter(array_column($counters, 'id')));
        $removed  = array_diff_key($existing, array_flip($kept));

        foreach ($removed as $name) {
            $stmt = $pdo->prepare('SELECT 1 FROM streams WHERE user_id = ? AND position(? IN title_template) > 0 LIMIT 1');
            $stmt->execute([$userId, '{' . $name . '}']);

            if ($stmt->fetchColumn() !== false) {
                throw new UserError(sprintf(__('ui.message.counter_in_use'), $name));
            }
        }

        $pdo->prepare('DELETE FROM user_counters WHERE user_id = ? AND NOT (id = ANY(CAST(? AS bigint[])))')
            ->execute([$userId, '{' . implode(',', $kept) . '}']);

        if ($renames !== []) {
            $rows = $pdo->prepare('SELECT id, title_template FROM streams WHERE user_id = ? AND title_template IS NOT NULL');
            $rows->execute([$userId]);
            $set = $pdo->prepare('UPDATE streams SET title_template = ? WHERE id = ?');

            foreach ($rows->fetchAll() as $row) {
                $renamed = self::rename((string) $row['title_template'], $renames);

                if ($renamed !== $row['title_template']) {
                    $set->execute([$renamed, $row['id']]);
                }
            }
        }

        $update = $pdo->prepare('UPDATE user_counters SET name = ?, value = ?, per_day = ?, updated_at = now() WHERE id = ? AND user_id = ?');
        $insert = $pdo->prepare('INSERT INTO user_counters (user_id, name, value, per_day) VALUES (?, ?, ?, ?)');

        foreach ($counters as $i => $counter) {
            if ($counter['id'] !== null) {
                $update->execute(['zzrenaming' . $i, $counter['value'], $counter['per_day'] ? 'true' : 'false', $counter['id'], $userId]);
            }
        }

        foreach ($counters as $counter) {
            if ($counter['id'] !== null) {
                $update->execute([$counter['name'], $counter['value'], $counter['per_day'] ? 'true' : 'false', $counter['id'], $userId]);
            } else {
                $insert->execute([$userId, $counter['name'], $counter['value'], $counter['per_day'] ? 'true' : 'false']);
            }
        }
    }

    /**
     * The template to keep for a title: the title itself when it uses one
     * of the user's counters, else null (a plain title needs none).
     */
    public static function templateFor(int $userId, string $title): ?string
    {
        $names = array_column(self::forUser($userId), 'name');

        return array_intersect(self::variables($title), $names) !== [] ? $title : null;
    }

    /**
     * What the content form needs to preview numbers: per counter, where
     * numbering starts, whether it counts days, and when each dated
     * content using it happens (with its day in the creator's time zone).
     *
     * @return array<string, array{base:int, per_day:bool, dates: list<array{0:int, 1:int, 2:string}>}> name => base, per_day and [epoch ms, content id, Y-m-d]
     */
    public static function timeline(int $userId): array
    {
        $timeline = [];

        foreach (self::forUser($userId) as $counter) {
            $timeline[$counter['name']] = ['base' => $counter['value'], 'per_day' => $counter['per_day'], 'dates' => []];
        }

        if ($timeline === []) {
            return $timeline;
        }

        $stmt = Database::connection()->prepare(
            "SELECT s.id, s.title_template AS prefixes, (extract(epoch FROM s.scheduled_start) * 1000)::bigint AS at,
                    to_char(s.scheduled_start AT TIME ZONE coalesce((SELECT name FROM pg_timezone_names WHERE name = u.timezone LIMIT 1), 'UTC'), 'YYYY-MM-DD') AS day
               FROM streams s JOIN users u ON u.id = s.user_id
              WHERE s.user_id = ? AND s.title_template IS NOT NULL AND s.scheduled_start IS NOT NULL AND s.status <> 'cancelled'"
        );
        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll() as $row) {
            foreach (self::variables((string) $row['prefixes']) as $name) {
                if (isset($timeline[$name])) {
                    $timeline[$name]['dates'][] = [(int) $row['at'], (int) $row['id'], (string) $row['day']];
                }
            }
        }

        return $timeline;
    }
}
