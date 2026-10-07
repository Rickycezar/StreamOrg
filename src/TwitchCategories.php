<?php
declare(strict_types=1);

/**
 * Finds the Twitch category of a catalogue game and stores it on the game,
 * so sending a stream to Twitch and live tracking know it without asking.
 *
 * Looked up with the app token, most certain first:
 *   1. by IGDB id — Twitch's categories are IGDB's games, so this is exact
 *      (games imported from IGDB);
 *   2. by exact name;
 *   3. by search, keeping only a close-enough title (LiveTracker::sameTitle:
 *      "Baldur's Gate 3" matches "Baldurs Gate 3", "Hades" never "Hades II").
 * Every game has a category: one Twitch does not list keeps the Just
 * Chatting stand-in (source "default") and is looked up again later; an
 * admin can also set it by hand.
 */
final class TwitchCategories
{
    /** How many games one "find categories" run looks up, to stay within a request. */
    public const BATCH = 25;

    public const JUST_CHATTING_NAME = 'Just Chatting';

    /** A game Twitch has no category for (yet) streams as Just Chatting. */
    public const DEFAULT_SOURCE = 'default';

    /** @return array{id:string, name:string}|null */
    public static function resolve(string $title, ?string $igdbId = null): ?array
    {
        if (!Twitch::isConfigured() || trim($title) === '') {
            return null;
        }

        if ($igdbId !== null && ctype_digit($igdbId)) {
            $hit = self::first(Twitch::app('GET', 'games', ['igdb_id' => $igdbId]));

            if ($hit !== null) {
                return $hit;
            }
        }

        foreach (self::names($title) as $name) {
            $hit = self::first(Twitch::app('GET', 'games', ['name' => $name]));

            if ($hit !== null) {
                return $hit;
            }

            $data = Http::json(Twitch::app('GET', 'search/categories', ['query' => mb_substr($name, 0, 80), 'first' => 10]));

            foreach ((array) ($data['data'] ?? []) as $row) {
                if (is_array($row) && isset($row['id'], $row['name']) && LiveTracker::sameTitle($title, (string) $row['name'])) {
                    return ['id' => (string) $row['id'], 'name' => (string) $row['name']];
                }
            }
        }

        return null;
    }

    /**
     * The names to look a title up by: as written, then without a store
     * edition suffix ("Cyberpunk 2077 Ultimate Edition" → "Cyberpunk 2077"),
     * since Twitch lists the base game only.
     *
     * @return list<string>
     */
    public static function names(string $title): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        $base  = preg_replace(
            '/[\s:\-–—]*\b(?:(?:game of the year|goty|definitive|ultimate|complete|deluxe|digital deluxe|enhanced|standard|gold|premium)\s+)?edition\b.*$/iu',
            '',
            $title
        );
        $base = trim((string) $base, " \t:-–—");

        return array_values(array_unique(array_filter([$title, $base], static fn (string $n): bool => $n !== '')));
    }

    /**
     * Looks a game up again while it only has the Just Chatting stand-in,
     * and stores what Twitch has for it. Never fails the caller: an import
     * must not break because Twitch is unreachable.
     *
     * @return array{id:string, name:string}|null the category now stored, if Twitch had one
     */
    public static function assign(PDO $pdo, int $gameId): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT title, source_provider, source_ref, twitch_category_id, twitch_category_name, twitch_category_source FROM games WHERE id = ?');
            $stmt->execute([$gameId]);
            $game = $stmt->fetch();

            if ($game === false) {
                return null;
            }

            if ($game['twitch_category_source'] !== self::DEFAULT_SOURCE) {
                return ['id' => (string) $game['twitch_category_id'], 'name' => (string) $game['twitch_category_name']];
            }

            $category = self::resolve((string) $game['title'], $game['source_provider'] === 'igdb' ? (string) $game['source_ref'] : null);

            if ($category !== null) {
                self::store($pdo, $gameId, $category, 'twitch');
            }

            return $category;
        } catch (Throwable $e) {
            ErrorLog::note("Twitch category for game #{$gameId}: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Sets a game's category; with null, puts back the Just Chatting
     * stand-in so the game is looked up again.
     *
     * @param 'twitch'|'manual' $source
     */
    public static function store(PDO $pdo, int $gameId, ?array $category, string $source = 'manual'): void
    {
        $pdo->prepare('UPDATE games SET twitch_category_id = ?, twitch_category_name = ?, twitch_category_source = ?, twitch_category_checked_at = NULL WHERE id = ?')
            ->execute([
                $category['id'] ?? LiveTracker::JUST_CHATTING,
                $category['name'] ?? self::JUST_CHATTING_NAME,
                $category === null ? self::DEFAULT_SOURCE : $source,
                $gameId,
            ]);
    }

    /** Games still streaming under the Just Chatting stand-in. */
    public static function defaults(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT count(*) FROM games WHERE twitch_category_source = 'default'")->fetchColumn();
    }

    /**
     * Looks up the next batch of games that only have the stand-in, those
     * never checked (or checked longest ago) first; with $skipRecent, not
     * those looked up within the last hour.
     *
     * @return array{checked:int, found:int, left:int}
     */
    public static function fillMissing(PDO $pdo, bool $skipRecent = false): array
    {
        $recent = $skipRecent ? "AND (twitch_category_checked_at IS NULL OR twitch_category_checked_at < now() - interval '1 hour')" : '';
        $ids    = $pdo->query(
            "SELECT id FROM games WHERE twitch_category_source = 'default' {$recent}
              ORDER BY twitch_category_checked_at NULLS FIRST, id LIMIT " . self::BATCH
        )->fetchAll(PDO::FETCH_COLUMN);

        $found = 0;
        $mark  = $pdo->prepare('UPDATE games SET twitch_category_checked_at = now() WHERE id = ?');

        foreach ($ids as $id) {
            if (self::assign($pdo, (int) $id) !== null) {
                $found++;
            } else {
                $mark->execute([$id]);
            }
        }

        $left = (int) $pdo->query(
            "SELECT count(*) FROM games WHERE twitch_category_source = 'default'
                AND (twitch_category_checked_at IS NULL OR twitch_category_checked_at < now() - interval '1 hour')"
        )->fetchColumn();

        return ['checked' => count($ids), 'found' => $found, 'left' => $left];
    }
    /** @return array{id:string, name:string}|null the first category in a Helix answer */
    private static function first(array $response): ?array
    {
        $row = Http::json($response)['data'][0] ?? null;

        return is_array($row) && isset($row['id'], $row['name']) ? ['id' => (string) $row['id'], 'name' => (string) $row['name']] : null;
    }
}
