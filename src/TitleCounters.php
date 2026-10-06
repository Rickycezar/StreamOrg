<?php
declare(strict_types=1);

/**
 * Counters for title prefixes (see 036_title_counters.sql): a prefix such
 * as "[STREAM #{stream}]" names a counter in braces, and each content
 * created with it takes that counter's next number.
 *
 * A counter's value is the last number given out, and can be set by hand.
 * The number is taken when the content is saved, under a row lock, so two
 * plans never get the same one; deleting a content gives its number back
 * while it is still the counter's latest (an older one stays used: its
 * title may already be on Twitch).
 */
final class TitleCounters
{
    public const MAX = 20;
    public const VALUE_MAX = 999999;
    public const NAME = '[a-z][a-z0-9_]{0,19}';

    /** @return list<array{id:int, name:string, value:int}> */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT id, name, value FROM user_counters WHERE user_id = ? ORDER BY name');
        $stmt->execute([$userId]);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'value' => (int) $row['value']],
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
     * Reads the counter rows of the defaults form: counter[n][id|name|value].
     * Blank names are dropped.
     *
     * @return list<array{id:?int, name:string, value:int}>
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
            $counters[]  = ['id' => $id ?: null, 'name' => $name, 'value' => (int) $value];
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
     * temporary name first, so two counters can swap names.
     *
     * @param list<array{id:?int, name:string, value:int}> $counters
     */
    public static function save(PDO $pdo, int $userId, array $counters): void
    {
        $keep = array_values(array_filter(array_column($counters, 'id')));

        $pdo->prepare('DELETE FROM user_counters WHERE user_id = ? AND NOT (id = ANY(CAST(? AS bigint[])))')
            ->execute([$userId, '{' . implode(',', $keep) . '}']);

        $update = $pdo->prepare('UPDATE user_counters SET name = ?, value = ?, updated_at = now() WHERE id = ? AND user_id = ?');
        $insert = $pdo->prepare('INSERT INTO user_counters (user_id, name, value) VALUES (?, ?, ?)');

        foreach ($counters as $i => $counter) {
            if ($counter['id'] !== null) {
                $update->execute(['zzrenaming' . $i, $counter['value'], $counter['id'], $userId]);
            }
        }

        foreach ($counters as $counter) {
            if ($counter['id'] !== null) {
                $update->execute([$counter['name'], $counter['value'], $counter['id'], $userId]);
            } else {
                $insert->execute([$userId, $counter['name'], $counter['value']]);
            }
        }
    }

    /**
     * The chosen prefixes, in the chosen order, with their counters' next
     * numbers taken: the counters move on (within the caller's transaction)
     * and the numbers are returned to be recorded on the content.
     *
     * @param list<int> $prefixIds
     * @return array{text:string, uses: array<int, int>} uses: counter id => number taken
     */
    public static function take(PDO $pdo, int $userId, array $prefixIds): array
    {
        $prefixIds = array_values(array_unique(array_filter(array_map('intval', $prefixIds))));

        if ($prefixIds === []) {
            return ['text' => '', 'uses' => []];
        }

        $stmt = $pdo->prepare('SELECT id, prefix FROM user_title_prefixes WHERE user_id = ? AND id = ANY(CAST(? AS bigint[]))');
        $stmt->execute([$userId, '{' . implode(',', $prefixIds) . '}']);
        $byId = array_column($stmt->fetchAll(), 'prefix', 'id');

        $chosen = array_values(array_filter(array_map(static fn (int $id): ?string => $byId[$id] ?? null, $prefixIds)));
        $names  = array_values(array_unique(array_merge(...array_map([self::class, 'variables'], $chosen ?: ['']))));

        $values = [];
        $uses   = [];

        if ($names !== []) {
            $stmt = $pdo->prepare(
                'UPDATE user_counters SET value = value + 1, updated_at = now()
                  WHERE user_id = ? AND name = ANY(CAST(? AS text[]))
              RETURNING id, name, value'
            );
            $stmt->execute([$userId, '{' . implode(',', $names) . '}']);

            foreach ($stmt->fetchAll() as $row) {
                $values[(string) $row['name']] = (int) $row['value'];
                $uses[(int) $row['id']]        = (int) $row['value'];
            }
        }

        return [
            'text' => implode(' ', array_map(static fn (string $p): string => self::render($p, $values), $chosen)),
            'uses' => $uses,
        ];
    }

    /** Records the numbers a new content took. */
    public static function record(PDO $pdo, int $streamId, array $uses): void
    {
        $insert = $pdo->prepare('INSERT INTO stream_counter_uses (stream_id, counter_id, value) VALUES (?, ?, ?)');

        foreach ($uses as $counterId => $value) {
            $insert->execute([$streamId, $counterId, $value]);
        }
    }

    /** Before a content is deleted: gives back each number it took that is still its counter's latest. */
    public static function release(PDO $pdo, int $streamId): void
    {
        $pdo->prepare(
            'UPDATE user_counters c
                SET value = c.value - 1, updated_at = now()
               FROM stream_counter_uses u
              WHERE u.stream_id = ? AND u.counter_id = c.id AND c.value = u.value AND c.value > 0'
        )->execute([$streamId]);
    }
}
