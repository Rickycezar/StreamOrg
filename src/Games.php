<?php
declare(strict_types=1);

/**
 * Games hosted by StreamOrg, such as the boss battle draw: each is a built
 * package in public/games/<name>/ with a manifest.json, kept as it was
 * handed over (bin/update_game.php copies a new version in). StreamOrg
 * speaks to a game only through the integration contract (version
 * CONTRACT): its manifest (version, languages, the draw options it
 * declares), its default texts (texts/<locale>.json) and the messages of
 * the giveaway page.
 *
 * Here: the hosted games and their manifests; their texts, with the
 * changes made in Administration → Game texts; the options a game
 * declares, checked against their declaration; and each creator's saved
 * options and game settings.
 */
final class Games
{
    public const CONTRACT = 1;

    public const SETTINGS_MAX = 8192;

    private const NAME = '/^[a-z0-9-]{2,40}$/';

    private const KEY = '/^[a-z0-9_.]{1,80}$/';

    private const LOCALE = '/^[A-Za-z]{2}(-[A-Za-z0-9]{2,8})?$/';

    /** @var array<string, ?array> */
    private static array $manifests = [];

    public static function dir(?string $game = null): string
    {
        return dirname(__DIR__) . '/public/games' . ($game !== null ? '/' . $game : '');
    }

    /** @return array<string, array> every hosted game with a manifest this StreamOrg understands, by name */
    public static function all(): array
    {
        $games = [];

        foreach (glob(self::dir() . '/*/manifest.json') ?: [] as $file) {
            $name = basename(dirname($file));
            $manifest = self::manifest($name);

            if ($manifest !== null) {
                $games[$name] = $manifest;
            }
        }

        ksort($games);

        return $games;
    }

    /** A hosted game's manifest, or null when there is none or it does not follow the contract. */
    public static function manifest(string $game): ?array
    {
        if (array_key_exists($game, self::$manifests)) {
            return self::$manifests[$game];
        }

        if (!preg_match(self::NAME, $game) || !is_file(self::dir($game) . '/manifest.json')) {
            return self::$manifests[$game] = null;
        }

        $manifest = json_decode((string) file_get_contents(self::dir($game) . '/manifest.json'), true);

        return self::$manifests[$game] = is_array($manifest) && self::problems($manifest, self::dir($game)) === [] ? $manifest : null;
    }

    /**
     * What is wrong with a package's manifest (empty when it can be hosted).
     *
     * @return list<string>
     */
    public static function problems(array $manifest, string $folder): array
    {
        $problems = [];

        if (!preg_match(self::NAME, (string) ($manifest['name'] ?? ''))) {
            $problems[] = 'name must match ' . self::NAME;
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', (string) ($manifest['version'] ?? ''))) {
            $problems[] = 'version must be major.minor.patch';
        }
        if (($manifest['contract'] ?? null) !== self::CONTRACT) {
            $problems[] = 'contract must be ' . self::CONTRACT . ' (this StreamOrg knows no other)';
        }

        $entry = (string) ($manifest['entry'] ?? '');
        if ($entry === '' || str_contains($entry, '..') || !is_file($folder . '/' . $entry)) {
            $problems[] = 'entry file missing';
        }

        $languages = $manifest['languages'] ?? null;
        if (!is_array($languages) || !in_array('en', $languages, true)) {
            $problems[] = 'languages must list en';
        }

        foreach ((array) ($manifest['options'] ?? []) as $i => $option) {
            if (self::optionProblem(is_array($option) ? $option : []) !== null) {
                $problems[] = 'option ' . $i . ': ' . self::optionProblem(is_array($option) ? $option : []);
            }
        }

        return $problems;
    }

    private static function optionProblem(array $o): ?string
    {
        if (!preg_match('/^[a-z0-9_]{1,40}$/', (string) ($o['key'] ?? ''))) {
            return 'bad key';
        }

        $type = (string) ($o['type'] ?? '');

        return match (true) {
            !in_array($type, ['bool', 'int', 'number', 'select', 'text'], true) => 'bad type',
            $type === 'select' && (!is_array($o['choices'] ?? null) || count($o['choices']) < 2 || count($o['choices']) > 20) => 'select needs 2-20 choices',
            self::checkOption($o, $o['default'] ?? null) === null => 'default is not valid',
            default => null,
        };
    }

    /** @return list<array> the draw options a game declares */
    public static function options(string $game): array
    {
        return array_values(array_filter((array) (self::manifest($game)['options'] ?? []), 'is_array'));
    }

    /**
     * Values for a game's options: each checked against its declaration;
     * missing or invalid values take the default, unknown keys are dropped.
     *
     * @return array<string, bool|int|float|string>
     */
    public static function cleanOptions(string $game, array $values): array
    {
        $clean = [];

        foreach (self::options($game) as $option) {
            $key = (string) $option['key'];
            $clean[$key] = self::checkOption($option, $values[$key] ?? null) ?? $option['default'];
        }

        return $clean;
    }

    /** A value for one option, or null when it does not fit the declaration. */
    private static function checkOption(array $o, mixed $value): bool|int|float|string|null
    {
        switch ($o['type'] ?? '') {
            case 'bool':
                return is_bool($value) ? $value : (in_array($value, ['1', 1, 'true', 'on'], true) ? true : (in_array($value, ['0', 0, 'false', ''], true) ? false : null));
            case 'int':
            case 'number':
                if (!is_numeric($value)) {
                    return null;
                }
                if ($o['type'] === 'int' && !is_int($value) && !preg_match('/^-?\d+$/', (string) $value)) {
                    return null;
                }
                $n = $o['type'] === 'int' ? (int) $value : (float) $value;
                if ((isset($o['min']) && $n < $o['min']) || (isset($o['max']) && $n > $o['max'])) {
                    return null;
                }
                return $n;
            case 'select':
                return is_string($value) && in_array($value, (array) ($o['choices'] ?? []), true) ? $value : null;
            case 'text':
                return is_string($value) && mb_strlen($value) <= min(120, (int) ($o['maxLength'] ?? 120)) ? $value : null;
            default:
                return null;
        }
    }

    /**
     * The languages a game can show: those it ships and those given texts
     * in Administration → Game texts.
     *
     * @return list<string>
     */
    public static function languages(string $game): array
    {
        $shipped = array_values(array_filter((array) (self::manifest($game)['languages'] ?? []), 'is_string'));

        try {
            $stmt = Database::connection()->prepare('SELECT DISTINCT locale FROM game_text_overrides WHERE game = ?');
            $stmt->execute([$game]);
            $added = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            $added = [];
        }

        $languages = array_values(array_unique(array_merge($shipped, $added)));
        sort($languages);

        return $languages;
    }

    /** Whether a language code can name a game's texts (en, pt-BR, es-419…). */
    public static function isLocale(string $locale): bool
    {
        return preg_match(self::LOCALE, $locale) === 1;
    }

    /** @return array<string, string> the texts a game ships for a language (texts/<locale>.json), or none */
    public static function defaults(string $game, string $locale): array
    {
        if (self::manifest($game) === null || !preg_match(self::LOCALE, $locale)) {
            return [];
        }

        $file = self::dir($game) . '/texts/' . $locale . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? array_filter($data, static fn ($v, $k): bool => is_string($v) && preg_match(self::KEY, (string) $k) === 1, ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * The texts a game shows in a language, as it resolves them: the
     * changes, then the language's own texts, then English.
     *
     * @return array<string, string>
     */
    public static function texts(string $game, string $locale): array
    {
        return array_replace(self::defaults($game, 'en'), self::defaults($game, $locale), self::overrides($game, $locale));
    }

    /** @return array<string, string> the texts changed in StreamOrg for a language */
    public static function overrides(string $game, string $locale): array
    {
        try {
            $stmt = Database::connection()->prepare('SELECT key, value FROM game_text_overrides WHERE game = ? AND locale = ? ORDER BY key');
            $stmt->execute([$game, $locale]);

            return array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (Throwable) {
            return [];
        }
    }

    /** The {placeholders} in a text, sorted. @return list<string> */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/i', $text, $found);
        $names = array_values(array_unique($found[1]));
        sort($names);

        return $names;
    }

    /**
     * Saves one language's texts as typed: blank or equal to the game's own
     * text clears the change. A text whose {placeholders} differ from the
     * game's is refused (it would break the game), as is one for a key the
     * game does not have.
     *
     * @param array<string, string> $values
     * @return array{saved:int, refused:list<string>}
     */
    public static function saveTexts(string $game, string $locale, array $values, ?int $userId): array
    {
        if (self::manifest($game) === null || !preg_match(self::LOCALE, $locale)) {
            throw new UserError(__('ui.message.invalid_input'));
        }

        $english = self::defaults($game, 'en');
        $own     = self::defaults($game, $locale);
        $pdo     = Database::connection();
        $put     = $pdo->prepare(
            'INSERT INTO game_text_overrides (game, locale, key, value, updated_by) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (game, locale, key) DO UPDATE SET value = excluded.value, updated_by = excluded.updated_by, updated_at = now()'
        );
        $clear   = $pdo->prepare('DELETE FROM game_text_overrides WHERE game = ? AND locale = ? AND key = ?');
        $saved   = 0;
        $refused = [];

        foreach ($values as $key => $value) {
            $key   = (string) $key;
            $value = trim(str_replace(["\r", "\n"], ' ', (string) $value));

            if (!isset($english[$key])) {
                continue;
            }

            $default = $own[$key] ?? null;

            if ($value === '' || $value === $default) {
                $clear->execute([$game, $locale, $key]);
                continue;
            }

            if (mb_strlen($value) > 500 || self::placeholders($value) !== self::placeholders($own[$key] ?? $english[$key])) {
                $refused[] = $key;
                continue;
            }

            $put->execute([$game, $locale, $key, $value, $userId]);
            $saved++;
        }

        return ['saved' => $saved, 'refused' => $refused];
    }

    /**
     * A creator's last options, the game's saved settings, and StreamOrg's
     * own draw rules (GameDraws::saveRules), off until chosen.
     *
     * @return array{options: array<string, mixed>, settings: ?array, sync_winners: bool, exclude_winners: bool}
     */
    public static function preferences(int $userId, string $game): array
    {
        $stmt = Database::connection()->prepare('SELECT options, settings, sync_winners, exclude_winners FROM game_preferences WHERE user_id = ? AND game = ?');
        $stmt->execute([$userId, $game]);
        $row = $stmt->fetch();

        return [
            'options'  => self::cleanOptions($game, $row ? (array) json_decode((string) $row['options'], true) : []),
            'settings' => $row && $row['settings'] !== null ? (json_decode((string) $row['settings'], true) ?: null) : null,
            'sync_winners'    => $row !== false && (bool) $row['sync_winners'],
            'exclude_winners' => $row !== false && (bool) $row['exclude_winners'],
        ];
    }

    /** Keeps a creator's options for a game (checked), as the next draws' starting point. */
    public static function saveOptions(int $userId, string $game, array $values): array
    {
        $clean = self::cleanOptions($game, $values);

        Database::connection()->prepare(
            'INSERT INTO game_preferences (user_id, game, options) VALUES (?, ?, CAST(? AS jsonb))
             ON CONFLICT (user_id, game) DO UPDATE SET options = excluded.options, updated_at = now()'
        )->execute([$userId, $game, json_encode((object) $clean)]);

        return $clean;
    }

    /**
     * Keeps the settings a game asked to save for a creator, as they came
     * (StreamOrg does not read them).
     *
     * @throws UserError when they are not an object or too big
     */
    public static function saveSettings(int $userId, string $game, mixed $settings): void
    {
        $json = is_array($settings) ? json_encode((object) $settings, JSON_UNESCAPED_UNICODE) : false;

        if ($json === false || strlen($json) > self::SETTINGS_MAX) {
            throw new UserError(__('ui.message.invalid_input'));
        }

        Database::connection()->prepare(
            'INSERT INTO game_preferences (user_id, game, settings) VALUES (?, ?, CAST(? AS jsonb))
             ON CONFLICT (user_id, game) DO UPDATE SET settings = excluded.settings, updated_at = now()'
        )->execute([$userId, $game, $json]);
    }

    /** Forgets the manifests read in this request (after a new version was copied in). */
    public static function forget(): void
    {
        self::$manifests = [];
    }
}
