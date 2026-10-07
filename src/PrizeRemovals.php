<?php
declare(strict_types=1);

/**
 * Administrators taking a key back from a giveaway winner, across every
 * streamer's giveaways.
 *
 * The winner is marked removed: their claim link stops working and the key
 * leaves their prizes. What becomes of the key depends on where it was:
 * a key set aside but not claimed goes back to the giveaway's prizes; a
 * claimed key (its code was seen) either returns to the streamer's vault
 * for another giveaway or stays there marked revoked, as the administrator
 * chooses. Every removal is kept with its reason, and the streamer (and the
 * winner, when they have a StreamOrg account) is told through a
 * notification. See 041_prize_removals.sql.
 */
final class PrizeRemovals
{
    public const STATES   = ['all', 'claimed', 'waiting', 'removed'];
    public const OUTCOMES = ['returned', 'revoked'];
    public const REASON_MAX = 500;

    /**
     * Winners matching a search (Twitch name, streamer, giveaway or game),
     * newest first, with their prize and state.
     *
     * @return list<array>
     */
    public static function search(string $query, string $state = 'all', int $limit = 100): array
    {
        $where  = [];
        $params = [];
        $query  = trim($query);

        if ($query !== '') {
            $where[] = "(w.twitch_login ILIKE :q OR w.display_name ILIKE :q OR u.username ILIKE :q OR u.display_name ILIKE :q
                         OR gv.title ILIKE :q OR g.title ILIKE :q OR w.twitch_user_id = :exact)";
            $params['q']     = '%' . strtr(ltrim($query, '@'), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            $params['exact'] = $query;
        }

        $where[] = match ($state) {
            'claimed' => 'w.claimed_at IS NOT NULL AND w.removed_at IS NULL',
            'waiting' => 'w.claimed_at IS NULL AND w.cancelled_at IS NULL AND w.expires_at > now()',
            'removed' => 'w.removed_at IS NOT NULL',
            default   => 'true',
        };

        $stmt = Database::connection()->prepare(
            'SELECT w.id, w.twitch_user_id, w.twitch_login, w.display_name, w.method, w.expires_at, w.claimed_at,
                    w.cancelled_at, w.removed_at, w.removed_reason, w.created_at,
                    gv.id AS giveaway_id, gv.title AS giveaway_title, gv.status AS giveaway_status,
                    coalesce(u.display_name, u.username) AS streamer, u.username AS streamer_username,
                    p.id AS prize_id, g.title AS game_title, gp.code AS platform_code,
                    r.game_title AS removed_game, r.platform_code AS removed_platform, r.key_outcome, coalesce(a.display_name, a.username) AS removed_by_name
               FROM giveaway_winners w
               JOIN giveaways gv ON gv.id = w.giveaway_id
               JOIN users u ON u.id = gv.user_id
          LEFT JOIN giveaway_prizes p ON p.winner_id = w.id
          LEFT JOIN game_keys k ON k.id = p.game_key_id
          LEFT JOIN games g ON g.id = k.game_id
          LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id
          LEFT JOIN LATERAL (SELECT game_title, platform_code, key_outcome FROM prize_removals WHERE winner_id = w.id ORDER BY id DESC LIMIT 1) r ON true
          LEFT JOIN users a ON a.id = w.removed_by
              WHERE ' . implode(' AND ', $where) . '
           ORDER BY coalesce(w.removed_at, w.claimed_at, w.created_at) DESC, w.id DESC
              LIMIT ' . max(1, min($limit, 500))
        );
        $stmt->execute($params);

        return array_map(static function (array $w): array {
            $w['state'] = self::stateOf($w);

            return $w;
        }, $stmt->fetchAll());
    }

    /** waiting, claimed, expired, withdrawn (by the streamer) or removed (by an administrator). */
    public static function stateOf(array $winner): string
    {
        return match (true) {
            $winner['removed_at'] !== null   => 'removed',
            $winner['claimed_at'] !== null   => 'claimed',
            $winner['cancelled_at'] !== null => 'cancelled',
            strtotime((string) $winner['expires_at']) < time() => 'expired',
            default => 'waiting',
        };
    }

    /**
     * Takes the key back from a winner.
     *
     * @param string $outcome for a claimed key: "returned" (back to the vault for another giveaway) or "revoked"
     * @return string what became of the key: freed, returned, revoked or none
     * @throws UserError when there is nothing to remove, or the reason is missing
     */
    public static function remove(int $adminId, int $winnerId, string $reason, string $outcome = 'revoked'): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new UserError(__('ui.message.prize_removal_reason_needed'));
        }

        $reason = mb_substr($reason, 0, self::REASON_MAX);

        $done = Database::transaction(static function (PDO $pdo) use ($adminId, $winnerId, $reason, $outcome): array {
            $stmt = $pdo->prepare(
                'SELECT w.*, gv.user_id AS streamer_id, gv.status AS giveaway_status, gv.title AS giveaway_title
                   FROM giveaway_winners w JOIN giveaways gv ON gv.id = w.giveaway_id
                  WHERE w.id = ? FOR UPDATE OF w'
            );
            $stmt->execute([$winnerId]);
            $winner = $stmt->fetch();

            if ($winner === false || $winner['removed_at'] !== null
                || ($winner['claimed_at'] === null && $winner['cancelled_at'] !== null)) {
                throw new UserError(__('ui.message.prize_removal_nothing'));
            }

            $stmt = $pdo->prepare(
                'SELECT p.*, g.title AS game_title, gp.code AS platform_code
                   FROM giveaway_prizes p
                   JOIN game_keys k ON k.id = p.game_key_id
                   JOIN games g ON g.id = k.game_id
              LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id
                  WHERE p.winner_id = ? FOR UPDATE OF p'
            );
            $stmt->execute([$winnerId]);
            $prize   = $stmt->fetch() ?: null;
            $claimed = $winner['claimed_at'] !== null && $prize !== null && $prize['claimed_at'] !== null;

            if ($claimed) {
                $outcome = in_array($outcome, self::OUTCOMES, true) ? $outcome : 'revoked';
                $ended   = in_array($winner['giveaway_status'], Giveaways::ENDED, true);

                $pdo->prepare('UPDATE game_keys SET status = ?, updated_at = now() WHERE id = ?')
                    ->execute([$outcome === 'returned' ? 'for_giveaway' : 'revoked', $prize['game_key_id']]);

                if ($outcome === 'returned' && !$ended) {
                    $keepCopy = Giveaways::needsCopies((int) $winner['streamer_id']);
                    $pdo->prepare('UPDATE giveaway_prizes SET winner_id = NULL, claimed_at = NULL, updated_at = now()'
                                  . ($keepCopy ? '' : ', sealed_code = NULL') . ' WHERE id = ?')
                        ->execute([$prize['id']]);
                } else {
                    $pdo->prepare('DELETE FROM giveaway_prizes WHERE id = ?')->execute([$prize['id']]);
                }
            } else {
                $outcome = $prize !== null ? 'freed' : 'none';

                if ($prize !== null) {
                    $pdo->prepare('UPDATE giveaway_prizes SET winner_id = NULL, updated_at = now() WHERE id = ?')->execute([$prize['id']]);
                }
            }

            $pdo->prepare(
                'UPDATE giveaway_winners SET cancelled_at = coalesce(cancelled_at, now()), removed_at = now(),
                        removed_by = ?, removed_reason = ? WHERE id = ?'
            )->execute([$adminId, $reason, $winnerId]);

            $pdo->prepare(
                'INSERT INTO prize_removals (winner_id, giveaway_id, game_key_id, game_title, platform_code, was_claimed, key_outcome, reason, removed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $winnerId, $winner['giveaway_id'], $prize['game_key_id'] ?? null, $prize['game_title'] ?? null,
                $prize['platform_code'] ?? null, $claimed ? 'true' : 'false', $outcome, $reason, $adminId,
            ]);

            return ['winner' => $winner, 'game' => $prize['game_title'] ?? null, 'outcome' => $outcome];
        });

        self::tell($done['winner'], $done['game'], $done['outcome'], $reason);

        return $done['outcome'];
    }

    /**
     * The latest removals, for the administrators' history.
     *
     * @return list<array>
     */
    public static function recent(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, w.twitch_login, w.display_name, gv.title AS giveaway_title,
                    coalesce(u.display_name, u.username) AS streamer, coalesce(a.display_name, a.username) AS removed_by_name
               FROM prize_removals r
               JOIN giveaway_winners w ON w.id = r.winner_id
               JOIN giveaways gv ON gv.id = r.giveaway_id
               JOIN users u ON u.id = gv.user_id
          LEFT JOIN users a ON a.id = r.removed_by
           ORDER BY r.created_at DESC, r.id DESC
              LIMIT ' . max(1, min($limit, 200))
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Tells the streamer, and the winner when their Twitch account is a StreamOrg user's. */
    private static function tell(array $winner, ?string $game, string $outcome, string $reason): void
    {
        $who  = (string) ($winner['display_name'] ?: $winner['twitch_login']);
        $what = $game ?? (string) $winner['giveaway_title'];

        Notifications::system(
            [(int) $winner['streamer_id']],
            'prize_removed_' . $outcome,
            [$who, $what, (string) $winner['giveaway_title'], $reason],
            '/giveaways/show?id=' . (int) $winner['giveaway_id'],
            'warning'
        );

        if ($winner['twitch_user_id'] === null) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'SELECT user_id FROM viewers WHERE twitch_user_id = ? AND user_id IS NOT NULL
              UNION SELECT user_id FROM twitch_connections WHERE twitch_user_id = ?'
        );
        $stmt->execute([$winner['twitch_user_id'], $winner['twitch_user_id']]);
        $users = array_diff(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), [(int) $winner['streamer_id']]);

        Notifications::system($users, 'prize_removed_winner', [$what, (string) $winner['giveaway_title']], null, 'warning');
    }
}
