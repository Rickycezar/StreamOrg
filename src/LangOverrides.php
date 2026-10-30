<?php
declare(strict_types=1);

/**
 * Interface texts changed in Administration → Languages.
 *
 * The lang/<locale>.php files are the defaults that ship with the code and
 * are replaced on every deploy, so admin edits are kept in the database
 * (lang_overrides) and laid over the files when the texts load. Clearing
 * an edit brings the file's text back. bin/lang_overrides.php copies the
 * edits into the files when they should become the new defaults.
 */
final class LangOverrides
{
    /** @var array<string, array<string, string>> locale => path => text */
    private static array $cache = [];

    /**
     * The edits for one language, by dotted path; none when the database
     * cannot be reached, so texts always load.
     *
     * @return array<string, string>
     */
    public static function forLocale(string $locale): array
    {
        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        try {
            $stmt = Database::connection()->prepare('SELECT path, value FROM lang_overrides WHERE locale = ?');
            $stmt->execute([$locale]);

            return self::$cache[$locale] = array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (Throwable) {
            return self::$cache[$locale] = [];
        }
    }

    /**
     * A language file's data with the edits laid over it. An edit only
     * replaces a text: one that would replace a whole group is skipped.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function apply(array $data, string $locale): array
    {
        foreach (self::forLocale($locale) as $path => $value) {
            $segments = explode('.', $path);
            $last     = array_pop($segments);
            $node     = &$data;
            $fits     = true;

            foreach ($segments as $segment) {
                if (!isset($node[$segment]) || !is_array($node[$segment])) {
                    $fits = false;
                    break;
                }
                $node = &$node[$segment];
            }

            if ($fits && !is_array($node[$last] ?? null)) {
                $node[$last] = $value;
            }

            unset($node);
        }

        return $data;
    }

    /**
     * Saves one group's texts as typed: a text equal to the file's, or
     * blank, clears its edit; anything else is kept as the edit.
     *
     * @param array<string|int, string> $values key => text as typed
     * @param array<string|int, string> $defaults key => the file's text
     * @return int how many texts are edited in the group now
     */
    public static function saveGroup(string $locale, string $group, array $values, array $defaults, ?int $userId): int
    {
        $pdo    = Database::connection();
        $put    = $pdo->prepare(
            'INSERT INTO lang_overrides (locale, path, value, updated_by) VALUES (?, ?, ?, ?)
             ON CONFLICT (locale, path) DO UPDATE SET value = excluded.value, updated_by = excluded.updated_by, updated_at = now()'
        );
        $clear  = $pdo->prepare('DELETE FROM lang_overrides WHERE locale = ? AND path = ?');
        $edited = 0;

        foreach ($values as $key => $value) {
            $key   = (string) $key;
            $value = trim((string) $value);

            if (!preg_match('/^[A-Za-z0-9_]{1,60}$/', $key)) {
                continue;
            }

            $path    = $group . '.' . $key;
            $default = isset($defaults[$key]) ? (string) $defaults[$key] : null;

            if ($value === '' || $value === $default) {
                $clear->execute([$locale, $path]);
                continue;
            }

            $put->execute([$locale, $path, mb_substr($value, 0, 4000), $userId]);
            $edited++;
        }

        unset(self::$cache[$locale]);

        return $edited;
    }

    /** @return int how many texts are edited, in one language or in all */
    public static function count(?string $locale = null): int
    {
        try {
            $stmt = Database::connection()->prepare('SELECT count(*) FROM lang_overrides' . ($locale !== null ? ' WHERE locale = ?' : ''));
            $stmt->execute($locale !== null ? [$locale] : []);

            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string, array<string, string>> every edit, locale => path => text */
    public static function all(): array
    {
        $all = [];

        foreach (Database::connection()->query('SELECT locale, path, value FROM lang_overrides ORDER BY locale, path') as $row) {
            $all[(string) $row['locale']][(string) $row['path']] = (string) $row['value'];
        }

        return $all;
    }

    /** Forgets what was read, after a change in this request. */
    public static function forget(): void
    {
        self::$cache = [];
    }
}
