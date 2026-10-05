<?php
declare(strict_types=1);

/**
 * The landing page's testimonials: a JSON document that admins upload,
 * edit or download under Administration → Testimonials.
 *
 * Kept in app_settings rather than on disk, so it survives deploys and is
 * part of the database backups. An empty list hides the section.
 *
 *     [
 *       {
 *         "name":   "RafaLimaTV",
 *         "quote":  { "en": "StreamOrg saved me…", "pt-BR": "O StreamOrg me salvou…" },
 *         "detail": { "en": "Variety · 48k followers", "pt-BR": "Variedade · 48 mil seguidores" },
 *         "avatar": "https://static-cdn.jtvnw.net/…/profile_image.png",
 *         "url":    "https://twitch.tv/rafalimatv",
 *         "rating": 5
 *       }
 *     ]
 *
 * "quote" and "detail" take a plain string or one string per language;
 * "detail", "avatar", "url" and "rating" (1–5, default 5) are optional.
 */
final class Testimonials
{
    private const KEY = 'landing_testimonials';

    public const MAX_ITEMS = 30;

    /** @return list<array{name:string, quote:string|array, detail:string|array|null, avatar:?string, url:?string, rating:int}> */
    public static function all(): array
    {
        $data = json_decode((string) Settings::get(self::KEY, '[]'), true);

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * The testimonials with their texts in one language.
     *
     * @return list<array{name:string, quote:string, detail:?string, avatar:?string, url:?string, rating:int}>
     */
    public static function forLocale(string $locale): array
    {
        return array_map(static fn (array $t): array => [
            'name'   => (string) $t['name'],
            'quote'  => (string) self::text($t['quote'] ?? '', $locale),
            'detail' => self::text($t['detail'] ?? null, $locale),
            'avatar' => $t['avatar'] ?? null,
            'url'    => $t['url'] ?? null,
            'rating' => (int) ($t['rating'] ?? 5),
        ], self::all());
    }

    /** The stored list, pretty-printed for editing and download. */
    public static function json(): string
    {
        return json_encode(self::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Validates a JSON document and returns the cleaned list.
     *
     * @return list<array<string,mixed>>
     * @throws UserError naming the first problem
     */
    public static function parse(string $json): array
    {
        $json = trim(preg_replace('/^\xEF\xBB\xBF/', '', $json));

        if ($json === '') {
            return [];
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UserError(sprintf(__('ui.message.testimonials_bad_json'), $e->getMessage()));
        }

        if (!is_array($data) || !array_is_list($data)) {
            throw new UserError(__('ui.message.testimonials_not_list'));
        }

        if (count($data) > self::MAX_ITEMS) {
            throw new UserError(sprintf(__('ui.message.testimonials_too_many'), self::MAX_ITEMS));
        }

        $clean = [];

        foreach ($data as $i => $item) {
            $n = $i + 1;

            if (!is_array($item)) {
                throw new UserError(sprintf(__('ui.message.testimonials_item'), $n, 'object'));
            }

            $name = trim((string) ($item['name'] ?? ''));

            if ($name === '' || mb_strlen($name) > 60) {
                throw new UserError(sprintf(__('ui.message.testimonials_item'), $n, 'name'));
            }

            $entry = ['name' => $name];

            foreach (['quote' => 500, 'detail' => 100] as $field => $max) {
                $value = self::cleanText($item[$field] ?? null, $max);

                if ($value === false || ($field === 'quote' && $value === null)) {
                    throw new UserError(sprintf(__('ui.message.testimonials_item'), $n, $field));
                }

                if ($value !== null) {
                    $entry[$field] = $value;
                }
            }

            foreach (['avatar', 'url'] as $field) {
                $value = trim((string) ($item[$field] ?? ''));

                if ($value === '') {
                    continue;
                }

                $local = $field === 'avatar' && preg_match('#^/(?![/\\\\])[\w./-]+$#', $value);

                if (!$local && safe_url($value) === null) {
                    throw new UserError(sprintf(__('ui.message.testimonials_item'), $n, $field));
                }

                $entry[$field] = $value;
            }

            $rating = $item['rating'] ?? 5;

            if (!is_int($rating) || $rating < 1 || $rating > 5) {
                throw new UserError(sprintf(__('ui.message.testimonials_item'), $n, 'rating'));
            }

            $entry['rating'] = $rating;
            $clean[] = $entry;
        }

        return $clean;
    }

    /** @param list<array<string,mixed>> $items already through parse() */
    public static function save(array $items, ?int $userId): void
    {
        Settings::set(self::KEY, (string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId);
    }

    /**
     * A plain string or a {"locale": "text"} map, trimmed and length-checked.
     *
     * @return string|array<string,string>|null|false false when invalid, null when absent
     */
    private static function cleanText(mixed $value, int $max): string|array|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            return mb_strlen($value) <= $max ? ($value === '' ? null : $value) : false;
        }

        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return false;
        }

        $out = [];

        foreach ($value as $locale => $text) {
            if (!is_string($text) || !preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', (string) $locale) || mb_strlen(trim($text)) > $max) {
                return false;
            }

            if (trim($text) !== '') {
                $out[(string) $locale] = trim($text);
            }
        }

        return $out === [] ? null : $out;
    }

    /** Picks a language from a text map: exact, same language, English, then any. */
    private static function text(string|array|null $value, string $locale): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        $language = strtolower(substr($locale, 0, 2));

        foreach ([$locale, $language, 'en'] as $want) {
            foreach ($value as $key => $text) {
                if (strcasecmp((string) $key, $want) === 0) {
                    return $text;
                }
            }
        }

        foreach ($value as $key => $text) {
            if (strtolower(substr((string) $key, 0, 2)) === $language) {
                return $text;
            }
        }

        return reset($value) ?: null;
    }
}
