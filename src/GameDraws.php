<?php
declare(strict_types=1);

/**
 * A giveaway's winners drawn by a hosted game (Games), such as the boss
 * battle draw: the config StreamOrg hands the game for a giveaway, and the
 * result it sends back, recorded as the giveaway's winners in the order
 * the game decided them. The whole result is kept as it came
 * (giveaway_game_draws), so every draw can be replayed and audited.
 */
final class GameDraws
{
    public const MAX_WINNERS = 10;

    public const RESULT_MAX = 262144;

    /**
     * How a winner's Twitch login becomes an account (tests replace it);
     * Twitch's own lookup when null.
     *
     * @var ?Closure(string): ?array{ref:string, login:string, name:string}
     */
    public static ?Closure $lookup = null;

    /** Whether winners can be drawn for this giveaway now. */
    public static function canDraw(array $giveaway): bool
    {
        return !$giveaway['is_direct'] && !in_array($giveaway['status'], Giveaways::ENDED, true);
    }

    /**
     * How many more winners the giveaway has prizes for: prizes nobody
     * holds, less the winners still to pick theirs. Null when it has no
     * prizes at all (the number of winners is then the streamer's).
     */
    public static function openSlots(int $giveawayId): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT (SELECT count(*) FROM giveaway_prizes p WHERE p.giveaway_id = ?) AS prizes,
                    (SELECT count(*) FROM giveaway_prizes p WHERE p.giveaway_id = ? AND p.claimed_at IS NULL AND p.winner_id IS NULL) AS free,
                    (SELECT count(*) FROM giveaway_winners w
                      WHERE w.giveaway_id = ? AND w.claimed_at IS NULL AND w.cancelled_at IS NULL AND w.expires_at > now()
                        AND NOT EXISTS (SELECT 1 FROM giveaway_prizes p WHERE p.winner_id = w.id)) AS picking'
        );
        $stmt->execute([$giveawayId, $giveawayId, $giveawayId]);
        $row = $stmt->fetch();

        return (int) $row['prizes'] === 0 ? null : max(0, (int) $row['free'] - (int) $row['picking']);
    }

    /**
     * The free prizes of a giveaway (no winner, not claimed), in its
     * order; only those in $ids, in the order given, when $ids is not
     * empty: a round's prizes. At most MAX_WINNERS.
     *
     * @param list<int> $ids
     * @return list<array{id:int, game_title:string, platform_code:?string}>
     */
    public static function freePrizes(int $giveawayId, array $ids = []): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.id, g.title AS game_title, gp.code AS platform_code
               FROM giveaway_prizes p
               JOIN game_keys k ON k.id = p.game_key_id
               JOIN games g ON g.id = k.game_id
          LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id
              WHERE p.giveaway_id = ? AND p.winner_id IS NULL AND p.claimed_at IS NULL
           ORDER BY p.position, p.id'
        );
        $stmt->execute([$giveawayId]);
        $free = [];

        foreach ($stmt->fetchAll() as $row) {
            $free[(int) $row['id']] = ['id' => (int) $row['id'], 'game_title' => (string) $row['game_title'], 'platform_code' => $row['platform_code']];
        }

        if ($ids === []) {
            return array_slice(array_values($free), 0, self::MAX_WINNERS);
        }

        $round = [];

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if (isset($free[$id])) {
                $round[] = $free[$id];
            }
        }

        return array_slice($round, 0, self::MAX_WINNERS);
    }

    /**
     * A round's prizes as asked for (prize ids, comma-separated): only in
     * a giveaway with a prize set per winner, only free ones.
     *
     * @return list<int>
     */
    public static function roundOf(array $giveaway, string $ids): array
    {
        if ($giveaway['winner_mode'] !== 'assigned' || trim($ids) === '') {
            return [];
        }

        return array_column(self::freePrizes((int) $giveaway['id'], array_map('intval', explode(',', $ids))), 'id');
    }

    /** @return list<string> the logins already winning this giveaway, who cannot enter again */
    public static function winnerLogins(int $giveawayId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT lower(twitch_login) FROM giveaway_winners WHERE giveaway_id = ? AND cancelled_at IS NULL AND twitch_login IS NOT NULL ORDER BY 1'
        );
        $stmt->execute([$giveawayId]);

        return array_slice($stmt->fetchAll(PDO::FETCH_COLUMN), 0, 500);
    }

    /**
     * The language a game shows in: the interface's when the game has it,
     * otherwise English.
     */
    public static function locale(string $game): string
    {
        return in_array(Lang::locale(), Games::languages($game), true) ? Lang::locale() : 'en';
    }

    /**
     * What the game is told for a giveaway's draw (the contract's
     * bossdraw:config). Only the channel is locked (the creator's own);
     * the chat command, prize text and number of winners are starting
     * values the creator may change in the game, the winners up to
     * maxWinners: the free prizes (at most MAX_WINNERS), which recording
     * checks again. The winners start at 1, or at that most when the
     * creator syncs them with the prizes; past winners of the giveaway
     * are left out only when the creator asks for it. The options start
     * from the creator's last ones. A round of chosen prizes (roundOf)
     * sets the winners to its size. A badge-gated giveaway sends its
     * badges, so only their holders can enter.
     *
     * @param ?array $options the options chosen on the page, or null for the creator's last ones
     * @param list<int> $round the round's prizes, in the order they are given
     */
    public static function config(int $userId, array $giveaway, string $game, ?array $options = null, array $round = []): array
    {
        $locale   = self::locale($game);
        $prefs    = Games::preferences($userId, $game);
        $slots    = self::openSlots((int) $giveaway['id']);
        $join     = ChatBot::commandsFor($userId)['join']['trigger'] ?? 'join';
        $channel  = strtolower((string) (TwitchUser::connection($userId)['twitch_login'] ?? ''));
        $texts    = Games::overrides($game, $locale);
        $most     = $round !== [] ? count($round) : max(1, min(self::MAX_WINNERS, $slots ?? self::MAX_WINNERS));
        $config   = [
            'lang'     => $locale,
            'channel'  => $channel,
            'entry'    => mb_substr('!' . $join, 0, 25),
            'prize'    => mb_substr((string) $giveaway['title'], 0, 120),
            'winners'  => $prefs['sync_winners'] || $round !== [] ? $most : 1,
            'maxWinners' => $most,
            'exclude'  => $prefs['exclude_winners'] ? self::winnerLogins((int) $giveaway['id']) : [],
            'lock'     => ['channel'],
            'giveaway' => mb_substr((string) $giveaway['title'], 0, 120),
            'options'  => (object) ($options !== null ? Games::cleanOptions($game, $options) : $prefs['options']),
        ];

        if ($prefs['settings'] !== null) {
            $config['settings'] = (object) $prefs['settings'];
        }
        if ($texts !== []) {
            $config['texts'] = (object) $texts;
        }
        if (($badges = GiveawayBadges::forGame($giveaway)) !== []) {
            $config['badges'] = $badges;
        }

        return $config;
    }

    /**
     * Records a game's result as the giveaway's winners, in its order (in
     * a giveaway with a prize set per winner, the first winner gets the
     * first prize, or with a round of chosen prizes the first of the
     * round), all or none, and keeps the result. The options it was
     * played with become the creator's next ones.
     *
     * Then the bot posts the winners' claim links in the chat, when the
     * giveaway asks for it.
     *
     * @param list<int> $round the round's prizes (roundOf), in the order they are given
     * @return array{count:int, posted:?bool} how many winners were recorded, and whether
     *         the bot took their links for the chat (null: not asked to)
     * @throws UserError when the result does not fit this giveaway or a winner cannot be recorded
     */
    public static function record(int $userId, int $giveawayId, string $game, mixed $result, array $round = []): array
    {
        $giveaway = Giveaways::find($userId, $giveawayId);

        if ($giveaway === null || !self::canDraw($giveaway) || Games::manifest($game) === null) {
            throw new UserError(__('ui.message.giveaway_not_editable'));
        }

        $winners = self::winnersOf($result, $game);
        $slots   = self::openSlots($giveawayId);

        if ($slots !== null && count($winners) > $slots) {
            throw new UserError(__('ui.message.giveaway_no_prizes_left'));
        }

        $round = $giveaway['winner_mode'] === 'assigned' ? array_column(self::freePrizes($giveawayId, $round), 'id') : [];

        if ($round !== [] && count($winners) > count($round)) {
            throw new UserError(__('ui.message.game_round_too_small'));
        }

        $accounts = [];

        foreach ($winners as $winner) {
            $account = self::$lookup !== null ? (self::$lookup)($winner['login']) : Twitch::user($winner['login'], true);

            if ($account === null) {
                throw new UserError(sprintf(__('ui.message.winner_not_found'), $winner['login']));
            }

            $accounts[] = $account;
        }

        $again  = !Games::preferences($userId, $game)['exclude_winners'];
        $tokens = Database::transaction(static function (PDO $pdo) use ($giveaway, $game, $result, $accounts, $again, $round): array {
            $tokens = [];

            foreach ($accounts as $i => $account) {
                $tokens[] = Giveaways::addDrawnWinner($giveaway, $account, $again, $round[$i] ?? null);
            }

            $pdo->prepare('INSERT INTO giveaway_game_draws (giveaway_id, game, version, winners, result) VALUES (?, ?, ?, ?, CAST(? AS jsonb))')
                ->execute([$giveaway['id'], $game, mb_substr((string) $result['version'], 0, 20), count($accounts), json_encode($result, JSON_UNESCAPED_UNICODE)]);
            $pdo->prepare('UPDATE giveaways SET drawn_at = now() WHERE id = ?')->execute([$giveaway['id']]);

            return $tokens;
        });

        if (is_array($result['options'] ?? null)) {
            Games::saveOptions($userId, $game, $result['options']);
        }

        return ['count' => count($accounts), 'posted' => Giveaways::postWinnerLinks($giveaway, $tokens)];
    }

    /**
     * Keeps a creator's StreamOrg rules for a game's draws: whether the
     * winners start synced with the free prizes, and whether past winners
     * of a giveaway are left out of its later draws.
     */
    public static function saveRules(int $userId, string $game, bool $syncWinners, bool $excludeWinners): void
    {
        Database::connection()->prepare(
            'INSERT INTO game_preferences (user_id, game, sync_winners, exclude_winners) VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, game) DO UPDATE SET sync_winners = excluded.sync_winners, exclude_winners = excluded.exclude_winners, updated_at = now()'
        )->execute([$userId, $game, $syncWinners ? 'true' : 'false', $excludeWinners ? 'true' : 'false']);
    }

    /**
     * The winners of a result, checked against the contract: the game's
     * own, at most MAX_WINNERS, each a Twitch login once.
     *
     * @return list<array{login:string, name:string}>
     * @throws UserError when the result is not one
     */
    private static function winnersOf(mixed $result, string $game): array
    {
        $json = is_array($result) ? json_encode($result) : false;

        if ($json === false || strlen($json) > self::RESULT_MAX || ($result['contract'] ?? null) !== Games::CONTRACT
            || ($result['game'] ?? null) !== $game || !is_string($result['version'] ?? null) || !is_array($result['winners'] ?? null)) {
            throw new UserError(__('ui.message.game_result_invalid'));
        }

        $winners = [];

        foreach ($result['winners'] as $winner) {
            $login = strtolower((string) (is_array($winner) ? ($winner['login'] ?? '') : ''));

            if (!preg_match('/^[a-z0-9_]{1,25}$/', $login) || isset($winners[$login])) {
                throw new UserError(__('ui.message.game_result_invalid'));
            }

            $winners[$login] = ['login' => $login, 'name' => mb_substr((string) ($winner['name'] ?? $login), 0, 40)];
        }

        if ($winners === [] || count($winners) > self::MAX_WINNERS) {
            throw new UserError(__('ui.message.game_result_invalid'));
        }

        return array_values($winners);
    }
}
