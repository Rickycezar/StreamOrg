<?php
declare(strict_types=1);

/**
 * Keeps planned content in step with what the Twitch channel is doing.
 *
 * Fed by TwitchEventSub with three facts — the channel went online, went
 * offline, or changed category — and applies these rules:
 *
 * - Category change while live: live content whose games do not include
 *   the new category is finished. If nothing is left live, the content
 *   planned for the session that matches the category starts. When
 *   nothing matches the streamer is between games (chatting, say), and
 *   the next change is checked the same way.
 * - Online: the current category is checked as above.
 * - Offline: live content is finished, and content planned for the
 *   session that never happened goes back to the undated backlog.
 *   Content planned for later the same day stays: there may be a second
 *   stream.
 *
 * "The session" runs from the start of the day the stream went live to
 * the end of the current day, in the user's time zone, so a stream that
 * crosses midnight still finds the evening's plans.
 *
 * Every action is written to twitch_live_log.
 */
final class LiveTracker
{
    /** Twitch's own "Just Chatting" category: matches content without games. */
    public const JUST_CHATTING = '509658';

    public static function online(int $userId, DateTimeImmutable $at, ?string $categoryId = null, ?string $categoryName = null): void
    {
        $conn = self::lockConnection($userId);

        if ($conn === null) {
            return;
        }

        if ($categoryId !== null && $categoryId !== '') {
            $conn['category_id']   = $categoryId;
            $conn['category_name'] = (string) $categoryName;
        }

        Database::connection()->prepare(
            'UPDATE twitch_connections SET is_live = true, live_since = ?, category_id = ?, category_name = ? WHERE user_id = ?'
        )->execute([$at->format(DATE_ATOM), $conn['category_id'], $conn['category_name'], $userId]);

        self::log($userId, 'online', null, $conn['category_name'], $at);

        if (!empty($conn['category_id'])) {
            self::follow($userId, (string) $conn['category_id'], (string) $conn['category_name'], $at, $at);
        }
    }

    public static function categoryChanged(int $userId, string $categoryId, string $categoryName, DateTimeImmutable $at): void
    {
        $conn = self::lockConnection($userId);

        if ($conn === null || $categoryId === '') {
            return;
        }

        $changed = $conn['category_id'] !== $categoryId;

        Database::connection()->prepare(
            'UPDATE twitch_connections SET category_id = ?, category_name = ? WHERE user_id = ?'
        )->execute([$categoryId, $categoryName, $userId]);

        if (!$changed || !$conn['is_live']) {
            return;
        }

        self::log($userId, 'category', null, $categoryName, $at);

        $since = $conn['live_since'] !== null ? new DateTimeImmutable($conn['live_since']) : $at;
        self::follow($userId, $categoryId, $categoryName, $at, $since);
    }

    public static function offline(int $userId, DateTimeImmutable $at): void
    {
        $conn = self::lockConnection($userId);

        if ($conn === null) {
            return;
        }

        $pdo   = Database::connection();
        $since = $conn['live_since'] !== null ? new DateTimeImmutable($conn['live_since']) : $at;

        foreach (self::liveStreams($userId) as $stream) {
            self::finish($userId, $stream, $at);
        }

        [$from] = self::window($userId, $since, $at);

        $stmt = $pdo->prepare(
            "UPDATE streams SET scheduled_start = NULL, updated_at = now()
              WHERE user_id = ? AND status = 'planned' AND collab_session_id IS NULL
                AND scheduled_start >= ? AND scheduled_start <= ?
          RETURNING id, title"
        );
        $stmt->execute([$userId, $from->format(DATE_ATOM), $at->format(DATE_ATOM)]);

        foreach ($stmt->fetchAll() as $row) {
            self::log($userId, 'unscheduled', (int) $row['id'], $row['title'], $at);
        }

        $pdo->prepare('UPDATE twitch_connections SET is_live = false, live_since = NULL WHERE user_id = ?')
            ->execute([$userId]);

        self::log($userId, 'offline', null, null, $at);
    }

    /**
     * Whether a Twitch category is one of these games: by the category id
     * remembered for the game (shared or the user's own pick), else by a
     * close-enough title. Content without games matches Just Chatting.
     *
     * @param list<array{id:int|string, title:string, category_id:?string}> $games
     * @return array{id:int, by:string}|null the matching game and how it matched ('id' or 'title'); id 0 for Just Chatting
     */
    public static function match(array $games, string $categoryId, string $categoryName): ?array
    {
        if ($games === []) {
            return $categoryId === self::JUST_CHATTING ? ['id' => 0, 'by' => 'id'] : null;
        }

        foreach ($games as $game) {
            if (($game['category_id'] ?? null) === $categoryId) {
                return ['id' => (int) $game['id'], 'by' => 'id'];
            }
        }

        foreach ($games as $game) {
            if (self::sameTitle((string) $game['title'], $categoryName)) {
                return ['id' => (int) $game['id'], 'by' => 'title'];
            }
        }

        return null;
    }

    /**
     * Titles that name the same game: equal once normalised, or a typo
     * apart. "Hades" and "Hades II" stay different.
     */
    public static function sameTitle(string $a, string $b): bool
    {
        $a = self::normalise($a);
        $b = self::normalise($b);

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        $length = max(strlen($a), strlen($b));

        return $length >= 8 && levenshtein($a, $b) <= intdiv($length, 12);
    }

    /** Lower case, plain letters and digits, roman numerals as digits, no edition suffix. */
    public static function normalise(string $title): string
    {
        $title = mb_strtolower($title, 'UTF-8');
        $title = strtr($title, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', '&' => ' and ',
        ]);
        $title = preg_replace("/['’`´]/u", '', $title);
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title);
        $title = preg_replace('/\b(?:(?:game of the year|goty|definitive|ultimate|complete|deluxe|digital deluxe|enhanced|standard|gold|premium)\s+)?edition\b.*$/', '', $title);
        $title = preg_replace('/^the\s+/', '', trim($title));

        $roman = ['ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6', 'vii' => '7', 'viii' => '8', 'ix' => '9', 'x' => '10'];
        $words = array_map(static fn (string $w): string => $roman[$w] ?? $w, explode(' ', $title));

        return trim(implode(' ', $words));
    }

    /** Applies a category to the session: see the class description. */
    private static function follow(int $userId, string $categoryId, string $categoryName, DateTimeImmutable $at, DateTimeImmutable $since): void
    {
        $stillLive = false;

        foreach (self::liveStreams($userId) as $stream) {
            if (self::matchStream($userId, $stream, $categoryId, $categoryName) !== null) {
                $stillLive = true;
                continue;
            }

            self::finish($userId, $stream, $at);
        }

        if ($stillLive) {
            return;
        }

        [$from, $to] = self::window($userId, $since, $at);

        $stmt = Database::connection()->prepare(
            "SELECT id, title, category_id FROM streams
              WHERE user_id = ? AND status = 'planned'
                AND scheduled_start >= ? AND scheduled_start < ?
           ORDER BY abs(extract(epoch FROM scheduled_start - ?::timestamptz)), scheduled_start"
        );
        $stmt->execute([$userId, $from->format(DATE_ATOM), $to->format(DATE_ATOM), $at->format(DATE_ATOM)]);

        foreach ($stmt->fetchAll() as $stream) {
            $match = self::matchStream($userId, $stream, $categoryId, $categoryName);

            if ($match === null) {
                continue;
            }

            if ($match['by'] === 'title' && $match['id'] > 0) {
                self::remember($userId, $match['id'], $categoryId, $categoryName);
            }

            Database::connection()->prepare(
                "UPDATE streams SET status = 'live', actual_start = coalesce(actual_start, ?), updated_at = now()
                  WHERE id = ? AND user_id = ?"
            )->execute([$at->format(DATE_ATOM), $stream['id'], $userId]);

            self::log($userId, 'started', (int) $stream['id'], $stream['title'], $at);

            return;
        }

        self::log($userId, 'chatting', null, $categoryName, $at);
    }

    private static function finish(int $userId, array $stream, DateTimeImmutable $at): void
    {
        Database::connection()->prepare(
            "UPDATE streams
                SET status = 'done', ended_at = greatest(?::timestamptz, coalesce(actual_start, ?::timestamptz)), updated_at = now()
              WHERE id = ? AND user_id = ?"
        )->execute([$at->format(DATE_ATOM), $at->format(DATE_ATOM), $stream['id'], $userId]);

        self::log($userId, 'finished', (int) $stream['id'], $stream['title'], $at);
    }

    /** A title match becomes this user's category pick for the game, unless they already have one. */
    private static function remember(int $userId, int $gameId, string $categoryId, string $categoryName): void
    {
        Database::connection()->prepare(
            'INSERT INTO user_twitch_categories (user_id, game_id, category_id, category_name)
             VALUES (?, ?, ?, ?) ON CONFLICT (user_id, game_id) DO NOTHING'
        )->execute([$userId, $gameId, $categoryId, $categoryName]);
    }

    /** @return list<array{id:int, title:string}> */
    private static function liveStreams(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, title, category_id FROM streams WHERE user_id = ? AND status = 'live' ORDER BY actual_start NULLS LAST, id"
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * Whether a category is this content's: its own Twitch category when it
     * has one, otherwise (or also) one of its games.
     *
     * @param array{id:int|string, category_id:?string} $stream
     * @return array{id:int, by:string}|null
     */
    private static function matchStream(int $userId, array $stream, string $categoryId, string $categoryName): ?array
    {
        if (($stream['category_id'] ?? null) !== null && $stream['category_id'] === $categoryId) {
            return ['id' => 0, 'by' => 'category'];
        }

        $games = self::games($userId, (int) $stream['id']);

        if ($games === [] && ($stream['category_id'] ?? null) !== null) {
            return null;
        }

        return self::match($games, $categoryId, $categoryName);
    }

    /** @return list<array{id:int, title:string, category_id:?string}> */
    private static function games(int $userId, int $streamId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT g.id, g.title, coalesce(utc.category_id, g.twitch_category_id) AS category_id
               FROM stream_games sg
               JOIN games g ON g.id = sg.game_id
          LEFT JOIN user_twitch_categories utc ON utc.game_id = g.id AND utc.user_id = ?
              WHERE sg.stream_id = ?
           ORDER BY sg.play_order'
        );
        $stmt->execute([$userId, $streamId]);

        return $stmt->fetchAll();
    }

    /**
     * From the start of the day the stream went live to the end of the
     * day of $at, in the user's time zone.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private static function window(int $userId, DateTimeImmutable $since, DateTimeImmutable $at): array
    {
        $stmt = Database::connection()->prepare('SELECT timezone FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $zone = new DateTimeZone((string) ($stmt->fetchColumn() ?: Config::get('app.timezone', 'UTC')));

        $from = $since->setTimezone($zone)->setTime(0, 0);
        $to   = $at->setTimezone($zone)->setTime(0, 0)->modify('+1 day');

        return [min($from, $at->setTimezone($zone)->setTime(0, 0)), $to];
    }

    /** The connection row, locked so overlapping notifications apply one at a time. */
    private static function lockConnection(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT is_live, live_since, category_id, category_name FROM twitch_connections WHERE user_id = ? FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private static function log(int $userId, string $kind, ?int $streamId, ?string $detail, DateTimeImmutable $at): void
    {
        Database::connection()->prepare(
            'INSERT INTO twitch_live_log (user_id, kind, stream_id, detail, at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $kind, $streamId, $detail, $at->format(DATE_ATOM)]);
    }
}
