<?php
declare(strict_types=1);

/**
 * Labels for database codes kept in code_labels (see 025_code_labels.sql):
 * the key sites, platforms and genres an admin adds at runtime, which the
 * shipped language files cannot know about.
 *
 * Lang consults these only where a language file has no label for a code.
 */
final class CodeLabels
{
    /** Lookup tables whose rows need a label: group => query listing their codes. */
    public const GROUPS = [
        'key_platform'       => 'SELECT code FROM key_platforms ORDER BY sort_order, code',
        'game_platform'      => 'SELECT code FROM game_platforms ORDER BY code',
        'streaming_platform' => 'SELECT code FROM streaming_platforms ORDER BY code',
        'genre'              => 'SELECT code FROM genres ORDER BY code',
    ];

    /** @var array<string, array<string, array<string, string>>>|null locale => group => code => label */
    private static ?array $cache = null;

    /** @return array<string, string> code => label for one group and locale */
    public static function group(string $group, string $locale): array
    {
        return self::all()[$locale][$group] ?? [];
    }

    public static function get(string $group, string $code, string $locale): ?string
    {
        return self::all()[$locale][$group][$code] ?? null;
    }

    /** Sets a label, or removes it when $label is empty. */
    public static function set(string $group, string $code, string $locale, string $label, ?int $userId): void
    {
        if (!isset(self::GROUPS[$group]) || !LangFile::isValidLocale($locale)) {
            throw new InvalidArgumentException('Unknown label group or locale.');
        }

        $label = trim($label);
        $pdo   = Database::connection();

        if ($label === '') {
            $pdo->prepare('DELETE FROM code_labels WHERE grp = ? AND code = ? AND locale = ?')->execute([$group, $code, $locale]);
        } else {
            $pdo->prepare(
                'INSERT INTO code_labels (grp, code, locale, label, updated_by) VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (grp, code, locale) DO UPDATE
                    SET label = EXCLUDED.label, updated_by = EXCLUDED.updated_by, updated_at = now()'
            )->execute([$group, $code, $locale, mb_substr($label, 0, 120), $userId]);
        }

        self::$cache = null;
    }

    /** @return array<string, list<string>> group => codes present in the database */
    public static function codes(): array
    {
        $pdo   = Database::connection();
        $codes = [];

        foreach (self::GROUPS as $group => $sql) {
            $codes[$group] = array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        }

        return $codes;
    }

    /** Drops the per-request cache, e.g. after a save in the same request. */
    public static function forget(): void
    {
        self::$cache = null;
    }

    /** @return array<string, array<string, array<string, string>>> */
    private static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];

        try {
            foreach (Database::connection()->query('SELECT grp, code, locale, label FROM code_labels') as $row) {
                self::$cache[$row['locale']][$row['grp']][$row['code']] = $row['label'];
            }
        } catch (Throwable) {
            self::$cache = [];
        }

        return self::$cache;
    }
}
