<?php
declare(strict_types=1);

/**
 * Writes into the shared catalogue (games, publishers, developers).
 *
 * Used by the admin import screen and by the CLI importers, so both create
 * rows the same way and dedupe against the same keys.
 */
final class GameCatalog
{
    /** Finds a game by exact title, case-insensitively. */
    public static function findByTitle(PDO $pdo, string $title): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM games WHERE lower(title) = lower(?) LIMIT 1');
        $stmt->execute([trim($title)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Pulls one record from a provider into the catalogue.
     *
     * Re-importing the same (provider, ref) updates that row instead of
     * creating a second one, and never overwrites a populated field with null.
     * With $category (the Twitch category the game was added from), a game
     * that only had the Just Chatting stand-in takes it; otherwise its
     * category is looked up.
     *
     * @param ?array $detail the provider's record, when the caller already fetched it
     * @return array{id:int, created:bool, title:string, release:?string, precision:string, publisher:?string, developer:?string}
     */
    public static function importFromProvider(PDO $pdo, Provider $provider, string $ref, bool $refreshImages = false, ?array $category = null, ?array $detail = null): array
    {
        $detail ??= $provider->fetch($ref);

        if ($detail === null || trim($detail['title']) === '') {
            throw new RuntimeException("Provider returned nothing for ref {$ref}.");
        }

        $code = $provider->code();

        $publisherId = self::company($pdo, 'publishers', $detail['publishers'][0] ?? null, $code);
        $developerId = self::company($pdo, 'developers', $detail['developers'][0] ?? null, $code);

        $stmt = $pdo->prepare(
            "INSERT INTO games
                 (title, slug, publisher_id, developer_id, release_date,
                  release_precision, release_raw, description, cover_url,
                  store_url, source_provider, source_ref, source_synced_at)
             VALUES (:title, :slug, :publisher_id, :developer_id, :release_date,
                     :precision, :raw, :description, :cover_url,
                     :store_url, :provider, :ref, now())
             ON CONFLICT (source_provider, source_ref)
             WHERE source_provider IS NOT NULL AND source_ref IS NOT NULL
             DO UPDATE SET title        = EXCLUDED.title,
                           publisher_id = COALESCE(EXCLUDED.publisher_id, games.publisher_id),
                           developer_id = COALESCE(EXCLUDED.developer_id, games.developer_id),
                           release_date = CASE
                               WHEN EXCLUDED.release_precision = 'unknown' THEN games.release_date
                               WHEN games.release_precision = 'day' AND EXCLUDED.release_precision <> 'day'
                                   THEN games.release_date
                               ELSE COALESCE(EXCLUDED.release_date, games.release_date)
                           END,
                           release_precision = CASE
                               WHEN EXCLUDED.release_precision = 'unknown' THEN games.release_precision
                               WHEN games.release_precision = 'day' AND EXCLUDED.release_precision <> 'day'
                                   THEN games.release_precision
                               ELSE EXCLUDED.release_precision
                           END,
                           release_raw  = COALESCE(EXCLUDED.release_raw, games.release_raw),
                           description  = COALESCE(EXCLUDED.description, games.description),
                           cover_url    = COALESCE(EXCLUDED.cover_url, games.cover_url),
                           store_url    = COALESCE(EXCLUDED.store_url, games.store_url),
                           source_synced_at = now()
             RETURNING id, (xmax = 0) AS inserted, release_date, release_precision"
        );

        $stmt->execute([
            'title'        => $detail['title'],
            'slug'         => self::slug($detail['title'] . '-' . $code . '-' . $ref),
            'publisher_id' => $publisherId,
            'developer_id' => $developerId,
            'release_date' => $detail['release_date'],
            'precision'    => $detail['release_precision']
                ?? ($detail['release_date'] !== null ? 'day' : 'unknown'),
            'raw'          => $detail['release_raw'] ?? null,
            'description'  => $detail['description'],
            'cover_url'    => $detail['cover_url'],
            'store_url'    => safe_url($detail['store_url'] ?? null),
            'provider'     => $code,
            'ref'          => $detail['ref'],
        ]);

        $row = $stmt->fetch();

        self::linkGenres($pdo, (int) $row['id'], $detail['genres']);

        try {
            GameImages::sync($pdo, (int) $row['id'], $detail['images'] ?? [], $refreshImages);

            foreach (GameImages::problems() as $problem) {
                ErrorLog::note("images for game #{$row['id']}: {$problem}");
            }
        } catch (Throwable $e) {
            ErrorLog::note("images for game #{$row['id']}: " . $e->getMessage());
        }

        if ($category !== null) {
            self::takeCategory($pdo, (int) $row['id'], $category);
        } else {
            TwitchCategories::assign($pdo, (int) $row['id']);
        }

        return self::summary($pdo, (int) $row['id'], (bool) $row['inserted']);
    }

    /**
     * Adds a game starting from its Twitch category, then fills in its
     * details from wherever they can be found, most exact first:
     *   1. Steam, by the app id IGDB records for the category's IGDB id;
     *   2. Steam, by a game there with exactly the category's name;
     *   3. IGDB itself, by that id;
     *   4. only Twitch's name and box art — always the case for categories
     *      with no IGDB id, which are the non-game ones (Just Chatting,
     *      Music…): a looser title search would find unrelated games.
     * A game already in the catalogue under that category (or that title)
     * is returned instead of added twice; if it was added by hand, it gets
     * the details now (see attach).
     *
     * @return array{id:int, created:bool, title:string, release:?string, precision:string, publisher:?string, developer:?string, via:string}
     */
    public static function addFromTwitch(PDO $pdo, string $categoryId): array
    {
        $category = Twitch::category($categoryId);

        if ($category === null) {
            throw new UserError(__('ui.message.invalid_input'));
        }

        $existing = self::findByCategory($pdo, $category);

        if ($existing !== null) {
            if (!self::isImported($pdo, $existing)) {
                foreach (self::detailSources($category) as [$provider, $ref]) {
                    try {
                        return self::attach($pdo, $existing, $provider, $ref) + ['via' => $provider->code()];
                    } catch (Throwable $e) {
                        ErrorLog::note("details for game #{$existing} via {$provider->code()}: " . $e->getMessage());
                    }
                }
            }

            return self::summary($pdo, $existing, false) + ['via' => 'catalog'];
        }

        foreach (self::detailSources($category) as [$provider, $ref]) {
            try {
                $result = Database::transaction(
                    static fn (PDO $pdo): array => self::importFromProvider($pdo, $provider, $ref, false, $category)
                );

                return $result + ['via' => $provider->code()];
            } catch (Throwable $e) {
                ErrorLog::note("add from Twitch #{$category['id']} via {$provider->code()}: " . $e->getMessage());
            }
        }

        $gameId = self::createPlain($pdo, $category['name'], $category);

        if ($category['box_art'] !== null) {
            try {
                GameImages::sync($pdo, $gameId, ['portrait' => $category['box_art']]);
            } catch (Throwable $e) {
                ErrorLog::note("box art for game #{$gameId}: " . $e->getMessage());
            }
        }

        return self::summary($pdo, $gameId, true) + ['via' => 'twitch'];
    }

    /**
     * The game a Twitch category already stands for: one stored with that
     * category (preferring the same title), or one with exactly that title
     * still waiting for its category, which takes it.
     */
    public static function findByCategory(PDO $pdo, array $category): ?int
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM games
              WHERE twitch_category_id = ? AND twitch_category_source <> 'default'
           ORDER BY lower(title) = lower(?) DESC, id
              LIMIT 1"
        );
        $stmt->execute([$category['id'], $category['name']]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $id = self::findByTitle($pdo, $category['name']);

        if ($id !== null) {
            self::takeCategory($pdo, $id, $category);
        }

        return $id;
    }

    /**
     * Where a Twitch category's details can come from, lazily: each source
     * is only looked up when the one before it gave nothing.
     *
     * @return Generator<array{0:Provider, 1:string}>
     */
    private static function detailSources(array $category): Generator
    {
        $igdb = IgdbProvider::forTwitch();

        if ($category['igdb_id'] === null) {
            return;
        }

        $steam = Providers::get('steam');
        $steam = $steam !== null && $steam->isAvailable() ? $steam : null;

        if ($steam !== null && $igdb !== null) {
            $appId = $igdb->steamAppId($category['igdb_id']);

            if ($appId !== null) {
                yield [$steam, $appId];
            }
        }

        if ($steam !== null) {
            $appId = self::steamMatch($steam, (string) $category['name']);

            if ($appId !== null) {
                yield [$steam, $appId];
            }
        }

        if ($igdb !== null) {
            yield [$igdb, $category['igdb_id']];
        }
    }

    /**
     * "HELLBOUND, Inc.™" and "Hellbound, Inc." are the same name, and so are
     * "Dressmaker (2026)" (Twitch tells namesakes apart by year) and "Dressmaker".
     */
    public static function sameName(string $a, string $b): bool
    {
        $fold = static fn (string $t): string => (string) preg_replace(
            '/[^\p{L}\p{N}]+/u',
            '',
            mb_strtolower(str_replace(['™', '®', '©'], '', (string) preg_replace('/\s*\((?:19|20)\d{2}\)\s*$/', '', $t)))
        );

        return $fold($a) !== '' && $fold($a) === $fold($b);
    }

    /** Steam results that are not the game itself: playtests, soundtracks, demos, servers… */
    public static function isSteamExtra(string $title): bool
    {
        return (bool) preg_match('/\b(playtest|soundtrack|ost|demo|dedicated server|sdk|artbook|art book|dlc|season pass|wallpaper)s?\b/iu', $title);
    }

    /** The Steam app with exactly this name (playtests, soundtracks and such aside), or null. */
    public static function steamMatch(Provider $steam, string $title): ?string
    {
        $term = trim((string) preg_replace('/\s*\((?:19|20)\d{2}\)\s*$/', '', $title));

        foreach ($steam->search($term) as $result) {
            if (!self::isSteamExtra($result['title']) && self::sameName($result['title'], $title)) {
                return (string) $result['ref'];
            }
        }

        return null;
    }

    /** Whether a game came from a provider (as opposed to being added by hand). */
    public static function isImported(PDO $pdo, int $gameId): bool
    {
        $stmt = $pdo->prepare('SELECT source_provider IS NOT NULL FROM games WHERE id = ?');
        $stmt->execute([$gameId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Gives a game added by hand its provider record and fills it in from
     * there, keeping the same row — its keys, content and embargoes stay
     * attached — and its Twitch category.
     *
     * @return array{id:int, created:bool, title:string, release:?string, precision:string, publisher:?string, developer:?string}
     * @throws UserError when another game already is that provider record
     */
    public static function attach(PDO $pdo, int $gameId, Provider $provider, string $ref, bool $refreshImages = true): array
    {
        $stmt = $pdo->prepare('SELECT id, title FROM games WHERE source_provider = ? AND source_ref = ? AND id <> ?');
        $stmt->execute([$provider->code(), $ref, $gameId]);
        $other = $stmt->fetch();

        if ($other !== false) {
            throw new UserError(sprintf(__('ui.message.game_already_linked'), $other['title'], (int) $other['id']));
        }

        $detail = $provider->fetch($ref);

        if ($detail === null || trim((string) $detail['title']) === '') {
            throw new UserError(__('ui.message.provider_error'));
        }

        $pdo->prepare('UPDATE games SET source_provider = ?, source_ref = ? WHERE id = ?')->execute([$provider->code(), $detail['ref'], $gameId]);

        $result = self::importFromProvider($pdo, $provider, (string) $detail['ref'], $refreshImages, null, $detail);

        if ($result['id'] !== $gameId) {
            throw new RuntimeException("attach: provider record {$provider->code()} {$ref} landed on #{$result['id']}, not #{$gameId}");
        }

        return ['created' => false] + $result;
    }

    /**
     * The Steam app a game added by hand most surely is: through its Twitch
     * category's IGDB record, else the Steam game with exactly its name.
     */
    public static function steamFor(PDO $pdo, int $gameId): ?string
    {
        $steam = Providers::get('steam');

        if ($steam === null || !$steam->isAvailable()) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT title, twitch_category_id, twitch_category_source FROM games WHERE id = ?');
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();

        if ($game === false) {
            return null;
        }

        if ($game['twitch_category_source'] !== TwitchCategories::DEFAULT_SOURCE && Twitch::isConfigured()) {
            $category = Twitch::category((string) $game['twitch_category_id']);
            $igdb     = IgdbProvider::forTwitch();

            if ($category !== null && $category['igdb_id'] !== null && $igdb !== null) {
                $appId = $igdb->steamAppId((string) $category['igdb_id']);

                if ($appId !== null) {
                    return $appId;
                }
            }
        }

        return self::steamMatch($steam, (string) $game['title']);
    }

    /**
     * Links every game added by hand that has a sure Steam match (see
     * steamFor) to it and fills it in, one game at a time.
     *
     * @return array{checked:int, converted:list<string>, missing:list<string>, failed:list<string>}
     */
    public static function convertManual(PDO $pdo): array
    {
        $steam  = Providers::get('steam');
        $games  = $pdo->query('SELECT id, title FROM games WHERE source_provider IS NULL ORDER BY title')->fetchAll();
        $result = ['checked' => count($games), 'converted' => [], 'missing' => [], 'failed' => []];

        if ($steam === null || !$steam->isAvailable()) {
            return $result;
        }

        foreach ($games as $game) {
            $appId = self::steamFor($pdo, (int) $game['id']);

            if ($appId === null) {
                $result['missing'][] = (string) $game['title'];
                continue;
            }

            try {
                $done = Database::transaction(static fn (PDO $tx): array => self::attach($tx, (int) $game['id'], $steam, $appId));
                $result['converted'][] = $done['title'];
            } catch (Throwable $e) {
                ErrorLog::note("convert game #{$game['id']} to Steam {$appId}: " . $e->getMessage());
                $result['failed'][] = $game['title'] . ($e instanceof UserError ? ' (' . $e->getMessage() . ')' : '');
            }
        }

        return $result;
    }

    /**
     * Adds a game found on Steam: the one already there for that app, or a
     * game added by hand with exactly that name (which gets the details),
     * or a new one; its Twitch category is looked up by name.
     *
     * @return array{id:int, created:bool, title:string, release:?string, precision:string, publisher:?string, developer:?string, via:string}
     */
    public static function addFromSteam(PDO $pdo, string $appId): array
    {
        $steam = Providers::get('steam');

        if ($steam === null || !$steam->isAvailable() || !ctype_digit($appId)) {
            throw new UserError(__('ui.message.provider_unavailable'));
        }

        $stmt = $pdo->prepare("SELECT id FROM games WHERE source_provider = 'steam' AND source_ref = ?");
        $stmt->execute([$appId]);
        $known = $stmt->fetchColumn();

        if ($known !== false) {
            return self::summary($pdo, (int) $known, false) + ['via' => 'catalog'];
        }

        $detail = $steam->fetch($appId);

        if ($detail === null) {
            throw new UserError(__('ui.message.provider_error'));
        }

        foreach ($pdo->query('SELECT id, title FROM games WHERE source_provider IS NULL')->fetchAll() as $plain) {
            if (self::sameName((string) $plain['title'], (string) $detail['title'])) {
                return self::attach($pdo, (int) $plain['id'], $steam, $appId, false) + ['via' => 'steam'];
            }
        }

        return self::importFromProvider($pdo, $steam, $appId, false, null, $detail) + ['via' => 'steam'];
    }

    /** Gives a game the category it was added from, unless it already has a real one. */
    private static function takeCategory(PDO $pdo, int $gameId, array $category): void
    {
        $pdo->prepare(
            "UPDATE games SET twitch_category_id = ?, twitch_category_name = ?, twitch_category_source = 'twitch'
              WHERE id = ? AND twitch_category_source = 'default'"
        )->execute([$category['id'], $category['name'], $gameId]);
    }

    /** @return array{id:int, created:bool, title:string, release:?string, precision:string, publisher:?string, developer:?string} */
    private static function summary(PDO $pdo, int $gameId, bool $created): array
    {
        $stmt = $pdo->prepare(
            'SELECT g.title, g.release_date, g.release_precision, p.name AS publisher, d.name AS developer
               FROM games g
          LEFT JOIN publishers p ON p.id = g.publisher_id
          LEFT JOIN developers d ON d.id = g.developer_id
              WHERE g.id = ?'
        );
        $stmt->execute([$gameId]);
        $row = $stmt->fetch();

        return [
            'id'        => $gameId,
            'created'   => $created,
            'title'     => (string) $row['title'],
            'release'   => $row['release_date'],
            'precision' => (string) $row['release_precision'],
            'publisher' => $row['publisher'],
            'developer' => $row['developer'],
        ];
    }

    /**
     * Re-queries the provider a game came from.
     *
     * The point is titles imported before a date was announced: a game
     * stored as "2027" can become a real date once the publisher says so.
     *
     * @return array{id:int, created:bool, title:string, release:?string, precision:string}
     */
    /** A game refreshed this recently is not fetched again (provider quota, image downloads). */
    public const REFRESH_COOLDOWN_MINUTES = 5;

    public static function refresh(PDO $pdo, int $gameId): array
    {
        $stmt = $pdo->prepare(
            'SELECT source_provider, source_ref, title,
                    source_synced_at > now() - make_interval(mins => ?) AS recent
               FROM games WHERE id = ?'
        );
        $stmt->execute([self::REFRESH_COOLDOWN_MINUTES, $gameId]);
        $game = $stmt->fetch();

        if ($game === false) {
            throw new UserError(__('ui.message.not_found'));
        }

        if ($game['recent']) {
            throw new UserError(sprintf(__('ui.message.refresh_too_soon'), self::REFRESH_COOLDOWN_MINUTES));
        }

        if (empty($game['source_provider']) || empty($game['source_ref'])) {
            throw new UserError(__('ui.message.refresh_not_imported'));
        }

        $provider = $game['source_provider'] === 'igdb' ? IgdbProvider::forTwitch() : Providers::get((string) $game['source_provider']);

        if ($provider === null || !$provider->isAvailable()) {
            throw new UserError(__('ui.message.provider_unavailable'));
        }

        return self::importFromProvider($pdo, $provider, (string) $game['source_ref'], true);
    }

    /**
     * Keeps a user's release embargo in step with the game's release date.
     *
     * A release date is the default embargo: coverage is not expected
     * before the game is out. Only applied when the date is exact, since a
     * year-only date would invent a deadline nobody agreed to, and never
     * over a row the user typed themselves.
     */
    public static function syncReleaseEmbargo(PDO $pdo, int $userId, int $gameId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT release_date FROM games
              WHERE id = ? AND release_date IS NOT NULL AND release_precision = 'day'"
        );
        $stmt->execute([$gameId]);
        $release = $stmt->fetchColumn();

        if ($release === false) {
            return false;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO game_embargoes (user_id, game_id, kind, lifts_at, source, label)
             VALUES (?, ?, 'release', ?, 'release_date', NULL)
             ON CONFLICT (user_id, game_id, kind, COALESCE(game_platform_id, 0))
             DO UPDATE SET lifts_at = EXCLUDED.lifts_at
                     WHERE game_embargoes.source <> 'manual'"
        );
        $stmt->execute([$userId, $gameId, $release]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Creates a bare game row when no provider match is available, with
     * the Twitch category it came from, or else one looked up.
     */
    public static function createPlain(PDO $pdo, string $title, ?array $category = null): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO games (title, slug) VALUES (?, ?)
             ON CONFLICT (slug) DO UPDATE SET title = EXCLUDED.title
             RETURNING id'
        );
        $stmt->execute([trim($title), self::slug($title)]);
        $gameId = (int) $stmt->fetchColumn();

        if ($category !== null) {
            self::takeCategory($pdo, $gameId, $category);
        } else {
            TwitchCategories::assign($pdo, $gameId);
        }

        return $gameId;
    }

    /** Finds or creates a company, matching on slug. */
    public static function company(PDO $pdo, string $table, ?string $name, string $provider): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $slug = self::slug($name);

        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE slug = ?");
        $stmt->execute([$slug]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false) {
            return (int) $existing;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO {$table} (name, slug, source_provider, source_ref)
             VALUES (?, ?, ?, ?) RETURNING id"
        );
        $stmt->execute([$name, $slug, $provider, $slug]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Links a game to genres already present in the lookup. Provider genre
     * names are free text, so unknown ones are skipped rather than creating
     * codes with no label.
     */
    public static function linkGenres(PDO $pdo, int $gameId, array $names): void
    {
        if ($names === []) {
            return;
        }

        $codes = array_map(
            static fn (string $n): string => strtolower(str_replace([' ', '-'], '_', trim($n))),
            $names
        );

        $placeholders = implode(',', array_fill(0, count($codes), '?'));

        $stmt = $pdo->prepare(
            "INSERT INTO game_genres (game_id, genre_id)
             SELECT ?, id FROM genres WHERE code IN ({$placeholders})
             ON CONFLICT DO NOTHING"
        );
        $stmt->execute([$gameId, ...$codes]);
    }

    /** ASCII slug; falls back to a hash when a title has no latin characters. */
    public static function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);

        if (is_string($transliterated)) {
            $slug = $transliterated;
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : substr(hash('sha256', $value), 0, 16);
    }
}
