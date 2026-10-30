<?php
declare(strict_types=1);

/**
 * Giveaways: prizes that point at keys in the streamer's vault, winners
 * identified by their Twitch account, and claim links.
 *
 * A prize is only a link to its key: nothing is copied when it is added.
 * With a recoverable vault the server reads the key from the vault at the
 * moment a winner claims it. A private vault cannot be opened without its
 * password, so the streamer makes the prizes claimable when they are ready
 * for winners: each code is then sealed with the giveaway's own lock (a
 * random 256-bit key wrapped with the application key, AES-256-GCM bound to
 * the giveaway and prize ids), and the copies can be taken back at any time.
 * Finishing a giveaway returns its unclaimed keys and deletes their copies.
 * A claimed key keeps a sealed copy, so the winner can always see it.
 *
 * A winner is a Twitch user id. Their claim link carries a random token;
 * the database keeps its SHA-256 for lookup and an encrypted copy so the
 * streamer can copy the link again. Redeeming needs a viewer signed in with
 * that very Twitch account (see Viewers), within the claim window.
 *
 * The prize keeps pointing at its key in the vault: claiming marks the
 * prize claimed and the key given away in one transaction.
 *
 * A direct gift (giveDirect) is a hidden giveaway (is_direct) with one key
 * assigned to one person: same link, same claim, never on the giveaways
 * page; claimed, it is finished, and cancelled, its key is free again.
 * See 027_giveaways_and_viewers.sql and 046_direct_gifts.sql.
 */
final class Giveaways
{
    public const STATUSES     = ['planned', 'open', 'closed', 'finished', 'cancelled'];

    /** Statuses in which prizes and winners can no longer change. */
    public const ENDED = ['finished', 'cancelled'];

    /** The longest chat message Twitch takes. */
    public const CHAT_MAX = 500;
    public const WINNER_MODES = ['pick', 'assigned'];
    public const ENTRY_METHODS = ['chat', 'external', 'manual'];

    private const CIPHER = 'aes-256-gcm';

    /**
     * Whether viewers can enter giveaways by chat. Off while the chat bot's
     * join command is hidden (ChatBot::HIDDEN): then entering by chat is
     * offered and shown nowhere — no option, no entries, no draw — and a
     * giveaway set to it reads as picked by hand.
     */
    public static function chatEntries(): bool
    {
        return !in_array('join', ChatBot::HIDDEN, true);
    }

    /**
     * The ways to take part a giveaway form offers.
     *
     * @return list<string>
     */
    public static function entryMethods(): array
    {
        return array_values(array_filter(self::ENTRY_METHODS, static fn (string $m): bool => $m !== 'chat' || self::chatEntries()));
    }

    /** A giveaway's way to take part as shown: chat reads as manual while chat entries are off. */
    public static function entryMethodOf(array $giveaway): string
    {
        $method = (string) $giveaway['entry_method'];

        return $method === 'chat' && !self::chatEntries() ? 'manual' : $method;
    }

    /** A giveaway of this user, or null. */
    public static function find(int $userId, int $giveawayId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM giveaways WHERE id = ? AND user_id = ?');
        $stmt->execute([$giveawayId, $userId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Whether a giveaway already has winners (withdrawn ones aside): its
     * winner mode can no longer change, as the claims depend on it.
     */
    public static function hasWinners(int $giveawayId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM giveaway_winners WHERE giveaway_id = ? AND cancelled_at IS NULL LIMIT 1');
        $stmt->execute([$giveawayId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Key statuses that can become a prize or a gift; an available key is then marked "for giveaway". */
    public const GIFTABLE = ['for_giveaway', 'available'];

    /**
     * Links vault keys to the giveaway as prizes: each must be this user's,
     * available or marked "for giveaway", hold a real code and not already
     * be a prize. Nothing is copied; the keys stay in the vault.
     *
     * @param list<int> $keyIds
     * @return array{added:int, skipped:list<string>} skipped: titles of keys left out
     */
    public static function addPrizes(int $userId, int $giveawayId, array $keyIds): array
    {
        $giveaway = self::find($userId, $giveawayId);

        if ($giveaway === null || in_array($giveaway['status'], self::ENDED, true)) {
            throw new UserError(__('ui.message.giveaway_not_editable'));
        }

        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT k.id, k.status, k.is_placeholder, g.title,
                    EXISTS (SELECT 1 FROM giveaway_prizes p WHERE p.game_key_id = k.id) AS taken
               FROM game_keys k JOIN games g ON g.id = k.game_id
              WHERE k.id = ? AND k.user_id = ?"
        );
        $insert = $pdo->prepare('INSERT INTO giveaway_prizes (giveaway_id, game_key_id) VALUES (?, ?)');

        $added   = 0;
        $skipped = [];

        foreach (array_unique(array_map('intval', $keyIds)) as $keyId) {
            $stmt->execute([$keyId, $userId]);
            $key = $stmt->fetch();

            if ($key === false) {
                continue;
            }

            if (!in_array($key['status'], self::GIFTABLE, true) || $key['taken'] || $key['is_placeholder']) {
                $skipped[] = (string) $key['title'];
                continue;
            }

            $insert->execute([$giveawayId, $keyId]);
            $pdo->prepare("UPDATE game_keys SET status = 'for_giveaway', updated_at = now() WHERE id = ? AND status = 'available'")->execute([$keyId]);
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /** Whether this streamer's prizes need copies before winners can claim them (a private vault). */
    public static function needsCopies(int $userId): bool
    {
        return Vault::mode($userId) === 'private';
    }

    /**
     * Makes the unclaimed prizes claimable without the vault's password:
     * copies each code into the giveaway's lock. Needs the vault open.
     *
     * @return int how many prizes were made claimable
     * @throws VaultLocked when the private vault is locked
     */
    public static function makeClaimable(int $userId, int $giveawayId): int
    {
        $giveaway = self::find($userId, $giveawayId);

        if ($giveaway === null || in_array($giveaway['status'], self::ENDED, true)) {
            throw new UserError(__('ui.message.giveaway_not_editable'));
        }

        $stmt = Database::connection()->prepare(
            'SELECT p.id, k.key_code FROM giveaway_prizes p JOIN game_keys k ON k.id = p.game_key_id
              WHERE p.giveaway_id = ? AND p.claimed_at IS NULL AND p.sealed_code IS NULL'
        );
        $stmt->execute([$giveawayId]);

        $lock  = self::lock($giveaway);
        $seal  = Database::connection()->prepare('UPDATE giveaway_prizes SET sealed_code = ? WHERE id = ?');
        $count = 0;

        foreach ($stmt->fetchAll() as $prize) {
            $plain = Vault::decryptCode($userId, (string) $prize['key_code']);

            if ($plain !== null && $plain !== '') {
                $seal->execute([self::seal($lock, $giveawayId, (int) $prize['id'], $plain), $prize['id']]);
                $count++;
            }
        }

        Database::connection()->prepare('UPDATE giveaways SET copies_taken_back_at = NULL WHERE id = ?')->execute([$giveawayId]);

        return $count;
    }

    /** Deletes the copies of the unclaimed prizes: they are claimable again only once made so. */
    public static function takeBack(int $userId, int $giveawayId): int
    {
        $stmt = Database::connection()->prepare(
            'UPDATE giveaway_prizes p SET sealed_code = NULL
               FROM giveaways g
              WHERE g.id = p.giveaway_id AND g.id = ? AND g.user_id = ?
                AND p.claimed_at IS NULL AND p.sealed_code IS NOT NULL'
        );
        $stmt->execute([$giveawayId, $userId]);
        $count = $stmt->rowCount();

        if ($count > 0) {
            Database::connection()->prepare('UPDATE giveaways SET copies_taken_back_at = now() WHERE id = ? AND user_id = ?')
                ->execute([$giveawayId, $userId]);
        }

        return $count;
    }

    /**
     * Where a key can be redeemed in one click, with the code filled in:
     * Steam and GOG accept it in the address. Null for other platforms.
     */
    public static function redeemUrl(?string $platformCode, string $code): ?string
    {
        return match ($platformCode) {
            'pc_steam' => 'https://store.steampowered.com/account/registerkey?key=' . rawurlencode($code),
            'pc_gog'   => 'https://www.gog.com/redeem/' . rawurlencode($code),
            default    => null,
        };
    }

    /** The store name for a one-click redeem button ("Steam", "GOG"), or null. */
    public static function redeemStore(?string $platformCode): ?string
    {
        return ['pc_steam' => 'Steam', 'pc_gog' => 'GOG'][$platformCode] ?? null;
    }

    /**
     * Concludes a giveaway: links not yet used stop working, unclaimed keys
     * return to the vault (still marked "for giveaway", ready for the next
     * one), their copies are deleted, and the chat entries go.
     *
     * @return int how many keys returned to the vault
     */
    public static function finish(int $userId, int $giveawayId, string $status = 'finished'): int
    {
        return Database::transaction(static function (PDO $pdo) use ($userId, $giveawayId, $status): int {
            $stmt = $pdo->prepare('UPDATE giveaways SET status = ?, updated_at = now() WHERE id = ? AND user_id = ?');
            $stmt->execute([$status, $giveawayId, $userId]);

            if ($stmt->rowCount() === 0) {
                return 0;
            }

            $pdo->prepare('UPDATE giveaway_winners SET cancelled_at = now() WHERE giveaway_id = ? AND claimed_at IS NULL AND cancelled_at IS NULL')
                ->execute([$giveawayId]);

            $stmt = $pdo->prepare('DELETE FROM giveaway_prizes WHERE giveaway_id = ? AND claimed_at IS NULL');
            $stmt->execute([$giveawayId]);

            self::clearEntries($giveawayId);

            return $stmt->rowCount();
        });
    }

    /** Takes an unclaimed prize out of the giveaway; the key stays in the vault. */
    public static function removePrize(int $userId, int $prizeId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM giveaway_prizes p USING giveaways g
              WHERE p.id = ? AND g.id = p.giveaway_id AND g.user_id = ? AND p.claimed_at IS NULL'
        );
        $stmt->execute([$prizeId, $userId]);

        return $stmt->rowCount() > 0;
    }

    /** Re-seals a prize's copy, if it has one, after its code was edited in the vault. */
    public static function refreshKey(int $userId, int $keyId, string $plain): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.id AS prize_id, g.* FROM giveaway_prizes p JOIN giveaways g ON g.id = p.giveaway_id
              WHERE p.game_key_id = ? AND g.user_id = ? AND p.claimed_at IS NULL AND p.sealed_code IS NOT NULL'
        );
        $stmt->execute([$keyId, $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return;
        }

        $prizeId = (int) $row['prize_id'];

        Database::connection()->prepare('UPDATE giveaway_prizes SET sealed_code = ? WHERE id = ?')
            ->execute([self::seal(self::lock($row), (int) $row['id'], $prizeId, $plain), $prizeId]);
    }

    /** The open giveaway prize a vault key belongs to, if any: its giveaway's name. */
    public static function prizeOf(int $userId, int $keyId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT g.title FROM giveaway_prizes p JOIN giveaways g ON g.id = p.giveaway_id
              WHERE p.game_key_id = ? AND g.user_id = ? AND p.claimed_at IS NULL'
        );
        $stmt->execute([$keyId, $userId]);

        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Records a winner and returns the claim token (to build the link). The
     * account is the one picked in the search (its Twitch id, looked up again
     * so the name is current), or else the one with this login.
     *
     * @throws UserError when the account does not exist, or the prize is not available
     */
    public static function addWinner(int $userId, int $giveawayId, string $login, string $method, ?int $prizeId = null, ?string $twitchId = null): string
    {
        $giveaway = self::find($userId, $giveawayId);

        if ($giveaway === null || $giveaway['status'] === 'cancelled') {
            throw new UserError(__('ui.message.giveaway_not_editable'));
        }

        $account = self::account($login, $twitchId);

        return self::recordWinner($giveaway, $account['ref'], $account['login'], $account['name'], $method, $prizeId);
    }

    /**
     * Draws a random entrant who has not won this giveaway yet.
     *
     * @return array{login:string, token:string}
     */
    public static function draw(int $userId, int $giveawayId, ?int $prizeId = null): array
    {
        $giveaway = self::find($userId, $giveawayId);

        if ($giveaway === null || $giveaway['status'] === 'cancelled') {
            throw new UserError(__('ui.message.giveaway_not_editable'));
        }

        $stmt = Database::connection()->prepare(
            'SELECT e.twitch_user_id, e.twitch_login, e.display_name FROM giveaway_entries e
              WHERE e.giveaway_id = ?
                AND NOT EXISTS (SELECT 1 FROM giveaway_winners w
                                 WHERE w.giveaway_id = e.giveaway_id AND w.twitch_user_id = e.twitch_user_id
                                   AND w.cancelled_at IS NULL)
           ORDER BY e.twitch_user_id'
        );
        $stmt->execute([$giveawayId]);
        $entries = $stmt->fetchAll();

        if ($entries === []) {
            throw new UserError(__('ui.message.giveaway_no_entries'));
        }

        $pick = $entries[random_int(0, count($entries) - 1)];
        $token = self::recordWinner($giveaway, $pick['twitch_user_id'], $pick['twitch_login'], $pick['display_name'], 'draw', $prizeId);

        Database::connection()->prepare('UPDATE giveaways SET drawn_at = now() WHERE id = ?')->execute([$giveawayId]);

        return ['login' => (string) $pick['twitch_login'], 'token' => $token];
    }

    /**
     * Records a winner a game drew (GameDraws), with the Twitch account
     * already looked up; inside the caller's transaction.
     *
     * @param array{ref:string, login:string, name:string} $account
     */
    public static function addDrawnWinner(array $giveaway, array $account): string
    {
        return self::recordWinner($giveaway, $account['ref'], $account['login'], $account['name'], 'draw', null);
    }

    /** Withdraws a winner whose prize was not claimed; an assigned prize is freed. */
    public static function cancelWinner(int $userId, int $winnerId): bool
    {
        return Database::transaction(static function (PDO $pdo) use ($winnerId, $userId): bool {
            $stmt = $pdo->prepare(
                'UPDATE giveaway_winners w SET cancelled_at = now()
                   FROM giveaways g
                  WHERE w.id = ? AND g.id = w.giveaway_id AND g.user_id = ?
                    AND w.claimed_at IS NULL AND w.cancelled_at IS NULL'
            );
            $stmt->execute([$winnerId, $userId]);

            if ($stmt->rowCount() === 0) {
                return false;
            }

            $pdo->prepare('UPDATE giveaway_prizes SET winner_id = NULL WHERE winner_id = ? AND claimed_at IS NULL')->execute([$winnerId]);

            $direct = $pdo->prepare('SELECT g.id FROM giveaway_winners w JOIN giveaways g ON g.id = w.giveaway_id WHERE w.id = ? AND g.is_direct');
            $direct->execute([$winnerId]);

            if (($giftId = $direct->fetchColumn()) !== false) {
                self::finish($userId, (int) $giftId, 'cancelled');
            }

            return true;
        });
    }

    /**
     * Gives one vault key straight to one Twitch account, without a
     * giveaway: a hidden giveaway with that key assigned to them. With a
     * private vault the vault must be open, so the key is copied for them
     * at once (nobody would come back to make it claimable).
     *
     * @return array{giveaway_id:int, winner_id:int, token:string, login:string}
     * @throws UserError when the key cannot be given or the account does not exist
     * @throws VaultLocked when the private vault is locked
     */
    public static function giveDirect(int $userId, int $keyId, string $login, ?string $twitchId, int $claimDays = 30): array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT k.id, k.status, k.is_placeholder, k.game_platform_id, g.title,
                    EXISTS (SELECT 1 FROM giveaway_prizes p WHERE p.game_key_id = k.id) AS taken
               FROM game_keys k JOIN games g ON g.id = k.game_id WHERE k.id = ? AND k.user_id = ?"
        );
        $stmt->execute([$keyId, $userId]);
        $key = $stmt->fetch();

        if ($key === false || !in_array($key['status'], self::GIFTABLE, true) || $key['taken'] || $key['is_placeholder']) {
            throw new UserError(__('ui.message.gift_key_unavailable'));
        }

        if (self::needsCopies($userId) && !Vault::isUnlocked($userId)) {
            throw new VaultLocked();
        }

        $account = self::account($login, $twitchId);
        $claimDays = max(1, min(365, $claimDays));

        $gift = Database::transaction(static function (PDO $pdo) use ($userId, $keyId, $key, $claimDays, $account): array {
            $stmt = $pdo->prepare(
                "INSERT INTO giveaways (user_id, title, status, winner_mode, entry_method, claim_days, is_direct)
                 VALUES (?, ?, 'open', 'assigned', 'manual', ?, true) RETURNING *"
            );
            $stmt->execute([$userId, mb_substr((string) $key['title'], 0, 200), $claimDays]);
            $giveaway = $stmt->fetch();

            $pdo->prepare('INSERT INTO giveaway_prizes (giveaway_id, game_key_id) VALUES (?, ?)')->execute([$giveaway['id'], $keyId]);
            $pdo->prepare("UPDATE game_keys SET status = 'for_giveaway', updated_at = now() WHERE id = ? AND status = 'available'")->execute([$keyId]);

            $token  = self::recordWinner($giveaway, $account['ref'], $account['login'], $account['name'], 'manual', null);
            $winner = $pdo->prepare('SELECT id FROM giveaway_winners WHERE giveaway_id = ? ORDER BY id DESC LIMIT 1');
            $winner->execute([$giveaway['id']]);

            return ['giveaway_id' => (int) $giveaway['id'], 'winner_id' => (int) $winner->fetchColumn(), 'token' => $token];
        });

        return $gift + ['login' => $account['login']];
    }

    /**
     * Every key given or promised by this streamer, newest first: one row
     * per winner (of a giveaway or a direct gift), with their key when it
     * is decided, when the link was made and when the key was taken.
     *
     * @return list<array<string, mixed>>
     */
    public static function given(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT w.id AS winner_id, w.twitch_user_id, w.twitch_login, w.display_name, w.method, w.created_at AS sent_at,
                    w.claimed_at, w.expires_at, w.cancelled_at, w.removed_at, w.claim_token, w.claim_token_hash,
                    gw.id AS giveaway_id, gw.title AS giveaway_title, gw.is_direct, gw.winner_mode,
                    k.id AS key_id, g.title AS game_title, gp.code AS platform_code, kp.code AS key_site,
                    art.path AS art_path, art.updated_at AS art_version
               FROM giveaway_winners w
               JOIN giveaways gw ON gw.id = w.giveaway_id
          LEFT JOIN giveaway_prizes p ON p.winner_id = w.id
          LEFT JOIN game_keys k ON k.id = p.game_key_id
          LEFT JOIN games g ON g.id = k.game_id
          LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id
          LEFT JOIN key_platforms kp ON kp.id = k.key_platform_id
          ' . GameImages::artJoin('k.game_id') . '
              WHERE gw.user_id = ?
           ORDER BY w.created_at DESC, w.id DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * Keys that can be given or added to a giveaway: "for giveaway" first,
     * then available, each by game.
     *
     * @return list<array<string, mixed>>
     */
    public static function giftableKeys(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT k.id, k.status, k.key_type, k.game_id, g.title, gp.code AS platform_code, k.expires_at
               FROM game_keys k
               JOIN games g ON g.id = k.game_id
          LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id
              WHERE k.user_id = ? AND k.status IN ('for_giveaway', 'available') AND NOT k.is_placeholder
                AND NOT EXISTS (SELECT 1 FROM giveaway_prizes p WHERE p.game_key_id = k.id)
           ORDER BY k.status = 'for_giveaway' DESC, g.title, k.id"
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * The Twitch account a winner is: the one picked in a search (by id,
     * looked up again so the name is current), or the one with this login.
     *
     * @return array{ref:string, login:string, name:string}
     * @throws UserError when there is no such account
     */
    private static function account(string $login, ?string $twitchId): array
    {
        $login = strtolower(ltrim(trim($login), '@'));

        if ($twitchId !== null && ctype_digit($twitchId)) {
            $account = Twitch::user($twitchId);
        } elseif (preg_match('/^[a-z0-9_]{3,25}$/', $login)) {
            $account = Twitch::user($login, true);
        } else {
            throw new UserError(__('ui.message.winner_bad_login'));
        }

        if ($account === null) {
            throw new UserError(sprintf(__('ui.message.winner_not_found'), $login !== '' ? $login : '#' . $twitchId));
        }

        return $account;
    }

    /** Gives an unclaimed winner a fresh claim window. */
    public static function extendWinner(int $userId, int $winnerId): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE giveaway_winners w SET expires_at = now() + make_interval(days => g.claim_days)
               FROM giveaways g
              WHERE w.id = ? AND g.id = w.giveaway_id AND g.user_id = ?
                AND w.claimed_at IS NULL AND w.cancelled_at IS NULL'
        );
        $stmt->execute([$winnerId, $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The claim link of a winner, for the streamer to copy again: on the
     * domain that speaks the streamer's language (streamorg.com.br for a
     * Portuguese streamer), as their audience most likely does.
     */
    public static function claimUrl(string $token, ?string $locale = null): string
    {
        return public_base_url($locale) . url('/claim') . '?t=' . rawurlencode($token);
    }

    /**
     * The public prize page of a giveaway (by its public_code), on the
     * domain of the streamer's language; with an empty code, the address
     * the code is appended to.
     */
    public static function publicUrl(string $code, ?string $locale = null): string
    {
        return public_base_url($locale) . url('/giveaway') . ($code === '' ? '' : '?g=' . rawurlencode($code));
    }

    /**
     * What the public prize page shows: the giveaway (never a direct gift,
     * a cancelled one, or a surprise before it opens), its streamer, and
     * each prize — the game, and who won it — never a code.
     *
     * @return array{giveaway:array, prizes:list<array>}|null
     */
    public static function publicView(string $code): ?array
    {
        if (!preg_match('/^[a-f0-9]{6,32}$/', $code)) {
            return null;
        }

        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT g.id, g.user_id, g.title, g.status, g.starts_at, g.ends_at, g.rules_note, g.keyword, g.entry_method,
                    g.is_surprise, u.display_name AS streamer_name, u.username AS streamer_username,
                    u.avatar_path, u.avatar_updated_at, u.locale AS streamer_locale, t.twitch_login
               FROM giveaways g
               JOIN users u ON u.id = g.user_id AND u.is_active
          LEFT JOIN twitch_connections t ON t.user_id = g.user_id
              WHERE g.public_code = ? AND NOT g.is_direct AND g.status <> 'cancelled'
                AND NOT (g.is_surprise AND g.status = 'planned')"
        );
        $stmt->execute([$code]);
        $giveaway = $stmt->fetch();

        if ($giveaway === false) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT p.id, k.game_id, g.title AS game_title, gp.code AS platform_code, p.claimed_at,
                    CASE WHEN w.id IS NULL OR w.removed_at IS NOT NULL THEN NULL
                         ELSE coalesce(nullif(w.display_name, ''), w.twitch_login) END AS winner_name
               FROM giveaway_prizes p
               JOIN game_keys k            ON k.id = p.game_key_id
               JOIN games g                ON g.id = k.game_id
          LEFT JOIN game_platforms gp      ON gp.id = k.game_platform_id
          LEFT JOIN giveaway_winners w     ON w.id = p.winner_id AND w.cancelled_at IS NULL
              WHERE p.giveaway_id = ?
           ORDER BY (w.id IS NOT NULL), lower(g.title), p.id"
        );
        $stmt->execute([$giveaway['id']]);

        return ['giveaway' => $giveaway, 'prizes' => $stmt->fetchAll()];
    }

    /**
     * The chat announcement of a giveaway, in the streamer's words (their
     * giveaway command's "announce" message): its name, how to enter when
     * it takes entries by chat now, and the prize page.
     */
    public static function announcement(array $giveaway): string
    {
        $userId = (int) $giveaway['user_id'];
        $locale = ChatBot::localeOf($userId);
        $entry  = '';

        if ($giveaway['status'] === 'open' && $giveaway['entry_method'] === 'chat' && !empty($giveaway['keyword'])) {
            $join  = ChatBot::joinCommand($userId, (string) $giveaway['keyword']);
            $entry = $join === null ? '' : ChatBot::render(ChatBot::message($userId, 'giveaway', 'entry'), ['command' => $join]);
        }

        return ChatBot::render(ChatBot::message($userId, 'giveaway', 'announce'), [
            'giveaway' => $giveaway['title'],
            'entry'    => $entry,
            'link'     => self::publicUrl((string) $giveaway['public_code'], $locale),
        ]);
    }

    /**
     * Has the chat bot post new winners' claim links in the streamer's
     * chat, tagging each winner (the giveaway built-in's "winner"
     * message), when the giveaway asks for it. Several winners share a
     * message up to Twitch's 500 characters, so a whole draw fits in the
     * bot's queue. A link opens only for its winner's Twitch account, so
     * posting it in chat gives nobody else the prize.
     *
     * @param list<string> $tokens the new winners' claim tokens
     * @return ?bool null when nothing was to be posted (switched off, an
     *               empty message, no winners), else whether the bot took it
     */
    public static function postWinnerLinks(array $giveaway, array $tokens): ?bool
    {
        $userId   = (int) $giveaway['user_id'];
        $template = ChatBot::message($userId, 'giveaway', 'winner');

        if (empty($giveaway['post_winner_link']) || $giveaway['is_direct'] || trim($template) === '' || $tokens === []) {
            return null;
        }

        $locale = ChatBot::localeOf($userId);
        $find   = Database::connection()->prepare('SELECT twitch_login FROM giveaway_winners WHERE claim_token_hash = ? AND giveaway_id = ?');
        $lines  = [];

        foreach ($tokens as $token) {
            $find->execute([hash('sha256', $token), $giveaway['id']]);
            $login = $find->fetchColumn();

            if (is_string($login) && $login !== '') {
                $lines[] = ChatBot::render($template, ['user' => $login, 'giveaway' => $giveaway['title'], 'link' => self::claimUrl($token, $locale)]);
            }
        }

        $messages = [];

        foreach ($lines as $line) {
            $last = array_key_last($messages);

            if ($last !== null && mb_strlen($messages[$last] . ' ' . $line) <= self::CHAT_MAX) {
                $messages[$last] .= ' ' . $line;
            } else {
                $messages[] = $line;
            }
        }

        try {
            foreach ($messages as $message) {
                ChatBot::post($userId, $message);
            }
        } catch (UserError) {
            return false;
        }

        return $messages === [] ? null : true;
    }

    /** Decrypts the stored copy of a winner's claim token. */
    public static function tokenOf(array $winner): ?string
    {
        return isset($winner['claim_token']) ? Crypto::decrypt($winner['claim_token']) : null;
    }

    /**
     * The claim behind a token, with what the claim page shows.
     *
     * @return array{winner:array, giveaway:array, prizes:list<array>}|null
     */
    public static function claimByToken(string $token): ?array
    {
        if ($token === '' || strlen($token) > 100) {
            return null;
        }

        $pdo  = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM giveaway_winners WHERE claim_token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        $winner = $stmt->fetch();

        if ($winner === false) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT g.*, u.display_name AS streamer_name, u.username AS streamer_username, u.avatar_path, u.avatar_updated_at
               FROM giveaways g JOIN users u ON u.id = g.user_id WHERE g.id = ?'
        );
        $stmt->execute([$winner['giveaway_id']]);
        $giveaway = $stmt->fetch();

        return [
            'winner'   => $winner,
            'giveaway' => $giveaway,
            'prizes'   => self::claimablePrizes($giveaway, $winner),
        ];
    }

    /**
     * Prizes this winner may take: in "pick" mode every unclaimed one, in
     * "assigned" mode only the one set aside for them.
     *
     * @return list<array>
     */
    public static function claimablePrizes(array $giveaway, array $winner): array
    {
        $sql = 'SELECT p.id, g.title, g.id AS game_id, gp.code AS platform_code, k.expires_at, k.content_type,
                       (p.sealed_code IS NOT NULL) AS ready
                  FROM giveaway_prizes p
                  JOIN game_keys k ON k.id = p.game_key_id
                  JOIN games g ON g.id = k.game_id
             LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id
                 WHERE p.giveaway_id = ? AND p.claimed_at IS NULL';

        $sql .= $giveaway['winner_mode'] === 'assigned' ? ' AND p.winner_id = ?' : ' AND p.winner_id IS NULL';
        $sql .= ' ORDER BY g.title, p.id';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($giveaway['winner_mode'] === 'assigned' ? [$giveaway['id'], $winner['id']] : [$giveaway['id']]);

        return $stmt->fetchAll();
    }

    /**
     * Claims a prize for the signed-in viewer and returns the key code — or
     * null when the key is still locked away (a private vault whose prizes
     * are not open): the prize is then reserved for this winner, and the
     * code is revealed by claiming again once the streamer opens the keys.
     *
     * The prize is the one already set aside for the winner (assigned, or
     * picked earlier) or, in "winner picks" mode, the chosen one. Every
     * check runs again inside the transaction, with the rows locked, so
     * two winners can never take the same key.
     *
     * @throws UserError with the reason it cannot be claimed
     */
    public static function claim(string $token, array $viewer, ?int $prizeId): ?string
    {
        return Database::transaction(static function (PDO $pdo) use ($token, $viewer, $prizeId): ?string {
            $stmt = $pdo->prepare('SELECT * FROM giveaway_winners WHERE claim_token_hash = ? FOR UPDATE');
            $stmt->execute([hash('sha256', $token)]);
            $winner = $stmt->fetch();

            if ($winner === false) {
                throw new UserError(__('ui.message.claim_not_found'));
            }

            self::assertClaimable($winner, $viewer);

            $stmt = $pdo->prepare('SELECT * FROM giveaways WHERE id = ?');
            $stmt->execute([$winner['giveaway_id']]);
            $giveaway = $stmt->fetch();

            $base = 'SELECT p.*, k.user_id, k.game_id, k.game_platform_id, k.key_code
                       FROM giveaway_prizes p JOIN game_keys k ON k.id = p.game_key_id
                      WHERE p.giveaway_id = ? AND p.claimed_at IS NULL';

            $stmt = $pdo->prepare($base . ' AND p.winner_id = ? LIMIT 1 FOR UPDATE OF p');
            $stmt->execute([$giveaway['id'], $winner['id']]);
            $prize = $stmt->fetch();

            if ($prize === false && $giveaway['winner_mode'] === 'pick' && $prizeId !== null) {
                $stmt = $pdo->prepare($base . ' AND p.winner_id IS NULL AND p.id = ? LIMIT 1 FOR UPDATE OF p');
                $stmt->execute([$giveaway['id'], $prizeId]);
                $prize = $stmt->fetch();

                if ($prize === false) {
                    $exists = $pdo->prepare('SELECT 1 FROM giveaway_prizes WHERE id = ? AND giveaway_id = ?');
                    $exists->execute([$prizeId, $giveaway['id']]);

                    throw new UserError(__($exists->fetchColumn() ? 'ui.message.claim_taken' : 'ui.message.claim_prize_gone'));
                }
            }

            if ($prize === false) {
                throw new UserError(__('ui.message.claim_prize_gone'));
            }

            $lock = self::lock($giveaway);
            $code = self::codeOf($giveaway, $lock, $prize);

            if ($code === null) {
                if (!self::needsCopies((int) $giveaway['user_id'])) {
                    throw new UserError(__('ui.message.claim_unreadable'));
                }

                $pdo->prepare('UPDATE giveaway_prizes SET winner_id = ?, updated_at = now() WHERE id = ?')
                    ->execute([$winner['id'], $prize['id']]);

                return null;
            }

            $embargo = self::embargoOf((int) $giveaway['user_id'], (int) $prize['game_id'], (int) $prize['game_platform_id']);

            if ($embargo !== null) {
                throw new UserError(sprintf(__('ui.message.claim_embargo'), fmt_datetime($embargo)));
            }

            $pdo->prepare('UPDATE giveaway_prizes SET winner_id = ?, claimed_at = now(), updated_at = now(), sealed_code = ? WHERE id = ?')
                ->execute([$winner['id'], $prize['sealed_code'] ?? self::seal($lock, (int) $giveaway['id'], (int) $prize['id'], $code), $prize['id']]);
            $pdo->prepare('UPDATE giveaway_winners SET claimed_at = now(), viewer_id = ? WHERE id = ?')
                ->execute([$viewer['id'], $winner['id']]);
            $pdo->prepare("UPDATE game_keys SET status = 'given_away', updated_at = now() WHERE id = ?")
                ->execute([$prize['game_key_id']]);

            if (!empty($giveaway['is_direct'])) {
                $pdo->prepare("UPDATE giveaways SET status = 'finished', updated_at = now() WHERE id = ?")->execute([$giveaway['id']]);
            }

            return $code;
        });
    }

    /**
     * The prize set aside for a winner and not claimed yet (assigned, or
     * picked while the keys were locked), with whether it can be revealed.
     *
     * @return array{id:int, title:string, game_id:int, platform_code:?string, ready:bool}|null
     */
    public static function reservedPrize(array $giveaway, array $winner): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.id, g.title, g.id AS game_id, gp.code AS platform_code, (p.sealed_code IS NOT NULL) AS has_copy
               FROM giveaway_prizes p
               JOIN game_keys k ON k.id = p.game_key_id
               JOIN games g ON g.id = k.game_id
          LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id
              WHERE p.winner_id = ? AND p.claimed_at IS NULL'
        );
        $stmt->execute([$winner['id']]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['ready'] = $row['has_copy'] || !self::needsCopies((int) $giveaway['user_id']);
        unset($row['has_copy']);

        return $row;
    }

    /**
     * What the streamer should know about winners and locked keys: winners
     * still waiting, and how many of them hold a key that is locked away.
     *
     * @return array{waiting:int, locked:int}
     */
    public static function waitingWinners(int $giveawayId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT count(*) AS waiting,
                    count(*) FILTER (WHERE EXISTS (SELECT 1 FROM giveaway_prizes p
                                                    WHERE p.giveaway_id = w.giveaway_id AND p.claimed_at IS NULL
                                                      AND p.sealed_code IS NULL)) AS locked
               FROM giveaway_winners w
              WHERE w.giveaway_id = ? AND w.claimed_at IS NULL AND w.cancelled_at IS NULL AND w.expires_at > now()'
        );
        $stmt->execute([$giveawayId]);
        $row = $stmt->fetch();

        return ['waiting' => (int) $row['waiting'], 'locked' => (int) $row['locked']];
    }

    /**
     * Keys a viewer has claimed, with their codes, newest first.
     *
     * @return list<array>
     */
    public static function prizesOf(array $viewer): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.id AS prize_id, p.sealed_code, p.claimed_at, g.*, gv.title AS game_title, gv.id AS game_id,
                    gp.code AS platform_code, k.expires_at AS redeem_by,
                    u.display_name AS streamer_name, u.username AS streamer_username, u.channel_handle
               FROM giveaway_winners w
               JOIN giveaway_prizes p ON p.winner_id = w.id AND p.claimed_at IS NOT NULL
               JOIN giveaways g ON g.id = w.giveaway_id
               JOIN users u ON u.id = g.user_id
               JOIN game_keys k ON k.id = p.game_key_id
               JOIN games gv ON gv.id = k.game_id
          LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id
              WHERE w.twitch_user_id = ? AND w.removed_at IS NULL
           ORDER BY p.claimed_at DESC'
        );
        $stmt->execute([$viewer['twitch_user_id']]);

        return array_map(static function (array $row): array {
            $row['code'] = self::open(self::lock($row), (int) $row['id'], (int) $row['prize_id'], (string) $row['sealed_code']);
            unset($row['sealed_code'], $row['lock_key']);

            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * The prize a winner claimed, with its code, for their claim page.
     *
     * @return array{id:int, title:string, game_id:int, platform_code:?string, expires_at:?string, code:?string}|null
     */
    public static function claimedPrize(array $winner): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.id, p.sealed_code, gv.id AS giveaway_id, gv.lock_key,
                    g.title, g.id AS game_id, gp.code AS platform_code, k.expires_at
               FROM giveaway_prizes p
               JOIN giveaways gv ON gv.id = p.giveaway_id
               JOIN game_keys k ON k.id = p.game_key_id
               JOIN games g ON g.id = k.game_id
          LEFT JOIN game_platforms gp ON gp.id = k.game_platform_id
              WHERE p.winner_id = ? AND p.claimed_at IS NOT NULL'
        );
        $stmt->execute([$winner['id']]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $lock = self::lock(['id' => $row['giveaway_id'], 'lock_key' => $row['lock_key']]);
        $row['code'] = self::open($lock, (int) $row['giveaway_id'], (int) $row['id'], (string) $row['sealed_code']);
        unset($row['sealed_code'], $row['lock_key']);

        return $row;
    }

    /**
     * Prize links waiting for this viewer: unclaimed, not cancelled, not expired.
     *
     * @return list<array{id:int, title:string, streamer:string, expires_at:string, token:?string}>
     */
    public static function pendingFor(array $viewer): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT w.id, w.claim_token, w.expires_at, g.title, g.is_direct, coalesce(u.display_name, u.username) AS streamer
               FROM giveaway_winners w
               JOIN giveaways g ON g.id = w.giveaway_id
               JOIN users u ON u.id = g.user_id
              WHERE w.twitch_user_id = ? AND w.claimed_at IS NULL AND w.cancelled_at IS NULL AND w.expires_at > now()
           ORDER BY w.expires_at'
        );
        $stmt->execute([$viewer['twitch_user_id']]);

        return array_map(static fn (array $w): array => $w + ['token' => self::tokenOf($w)], $stmt->fetchAll());
    }

    /** Deletes the entries of a finished giveaway: they are not kept after the draw. */
    public static function clearEntries(int $giveawayId): void
    {
        Database::connection()->prepare('DELETE FROM giveaway_entries WHERE giveaway_id = ?')->execute([$giveawayId]);
    }

    /** @throws UserError */
    private static function assertClaimable(array $winner, array $viewer): void
    {
        if ($winner['cancelled_at'] !== null) {
            throw new UserError(__('ui.message.claim_cancelled'));
        }

        if ($winner['claimed_at'] !== null) {
            throw new UserError(__('ui.message.claim_done'));
        }

        if (strtotime((string) $winner['expires_at']) < time()) {
            throw new UserError(__('ui.message.claim_expired'));
        }

        if ((string) $winner['twitch_user_id'] !== (string) $viewer['twitch_user_id']) {
            throw new UserError(__('ui.message.claim_not_yours'));
        }
    }

    private static function recordWinner(array $giveaway, ?string $twitchId, string $login, ?string $name, string $method, ?int $prizeId): string
    {
        if (!in_array($method, ['draw', 'external', 'manual'], true)) {
            $method = 'manual';
        }

        return Database::transaction(static function (PDO $pdo) use ($giveaway, $twitchId, $login, $name, $method, $prizeId): string {
            $dupe = $pdo->prepare('SELECT 1 FROM giveaway_winners WHERE giveaway_id = ? AND twitch_user_id = ? AND cancelled_at IS NULL');
            $dupe->execute([$giveaway['id'], $twitchId]);

            if ($dupe->fetchColumn()) {
                throw new UserError(sprintf(__('ui.message.winner_already'), $login));
            }

            $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

            $stmt = $pdo->prepare(
                'INSERT INTO giveaway_winners (giveaway_id, twitch_user_id, twitch_login, display_name, method,
                                               claim_token_hash, claim_token, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, now() + make_interval(days => ?)) RETURNING id'
            );
            $stmt->execute([$giveaway['id'], $twitchId, $login, $name, $method, hash('sha256', $token), Crypto::encrypt($token), (int) $giveaway['claim_days']]);
            $winnerId = (int) $stmt->fetchColumn();

            if ($giveaway['winner_mode'] === 'assigned') {
                $assign = $pdo->prepare(
                    'UPDATE giveaway_prizes SET winner_id = ?
                      WHERE id = (SELECT id FROM giveaway_prizes
                                   WHERE giveaway_id = ? AND winner_id IS NULL AND claimed_at IS NULL'
                    . ($prizeId !== null ? ' AND id = ?' : '') . '
                                ORDER BY id LIMIT 1 FOR UPDATE)'
                );
                $assign->execute($prizeId !== null ? [$winnerId, $giveaway['id'], $prizeId] : [$winnerId, $giveaway['id']]);

                if ($assign->rowCount() === 0) {
                    throw new UserError(__('ui.message.giveaway_no_prizes_left'));
                }

                self::prepareAssigned($giveaway, $winnerId);
            }

            return $token;
        });
    }

    /**
     * With a private vault, an assigned prize is copied for its winner right
     * away when the vault is open — the streamer is there, setting the winner
     * — so only that one key leaves the vault. Otherwise "make claimable"
     * does it later.
     */
    private static function prepareAssigned(array $giveaway, int $winnerId): void
    {
        $userId = (int) $giveaway['user_id'];

        if (!self::needsCopies($userId) || !Vault::isUnlocked($userId)) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'SELECT p.id, k.key_code FROM giveaway_prizes p JOIN game_keys k ON k.id = p.game_key_id
              WHERE p.winner_id = ? AND p.sealed_code IS NULL'
        );
        $stmt->execute([$winnerId]);
        $prize = $stmt->fetch();

        if ($prize === false) {
            return;
        }

        $plain = Vault::decryptCode($userId, (string) $prize['key_code']);

        if ($plain !== null && $plain !== '') {
            Database::connection()->prepare('UPDATE giveaway_prizes SET sealed_code = ? WHERE id = ?')
                ->execute([self::seal(self::lock($giveaway), (int) $giveaway['id'], (int) $prize['id'], $plain), $prize['id']]);
        }
    }

    /**
     * A prize's code: from its copy when it has one, otherwise straight from
     * a recoverable vault. Null when neither is possible (a private vault
     * whose prizes were not made claimable).
     */
    private static function codeOf(array $giveaway, string $lock, array $prize): ?string
    {
        if (!empty($prize['sealed_code'])) {
            return self::open($lock, (int) $giveaway['id'], (int) $prize['id'], (string) $prize['sealed_code']);
        }

        $owner = (int) $giveaway['user_id'];

        if (self::needsCopies($owner)) {
            return null;
        }

        return Vault::decryptCode($owner, (string) $prize['key_code']);
    }

    /** The latest active embargo of the streamer on a game for a platform, if any. */
    private static function embargoOf(int $userId, int $gameId, int $platformId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT max(lifts_at) FROM game_embargoes
              WHERE user_id = ? AND game_id = ? AND lifts_at > now()
                AND (game_platform_id IS NULL OR game_platform_id = ?)'
        );
        $stmt->execute([$userId, $gameId, $platformId]);

        return $stmt->fetchColumn() ?: null;
    }

    /** The giveaway's lock key, created on first use. */
    private static function lock(array $giveaway): string
    {
        if (!empty($giveaway['lock_key'])) {
            $key = base64_decode((string) Crypto::decrypt($giveaway['lock_key']), true);

            if (is_string($key) && strlen($key) === 32) {
                return $key;
            }

            throw new RuntimeException('Giveaway lock is unreadable.');
        }

        $key  = random_bytes(32);
        $stmt = Database::connection()->prepare('UPDATE giveaways SET lock_key = ? WHERE id = ? AND lock_key IS NULL');
        $stmt->execute([Crypto::encrypt(base64_encode($key)), $giveaway['id']]);

        if ($stmt->rowCount() === 0) {
            $stmt = Database::connection()->prepare('SELECT * FROM giveaways WHERE id = ?');
            $stmt->execute([$giveaway['id']]);

            return self::lock($stmt->fetch());
        }

        return $key;
    }

    private static function seal(string $lock, int $giveawayId, int $prizeId, string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $lock, OPENSSL_RAW_DATA, $iv, $tag, "giveaway:{$giveawayId}:prize:{$prizeId}");

        if ($cipher === false) {
            throw new RuntimeException('Sealing failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    private static function open(string $lock, int $giveawayId, int $prizeId, string $sealed): ?string
    {
        $raw = base64_decode($sealed, true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $lock, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), "giveaway:{$giveawayId}:prize:{$prizeId}");

        return $plain === false ? null : $plain;
    }
}
