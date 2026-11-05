<?php
declare(strict_types=1);

/**
 * Keys passed from one creator's vault to another's, without anyone
 * seeing the code (see 061_key_transfers.sql).
 *
 * Sending reads each code from the sender's vault (a private one must be
 * open) and seals it with the transfer's own lock: a random 256-bit key
 * wrapped with the application key, AES-256-GCM bound to the transfer and
 * item ids. While the transfer waits, the sender's keys are locked: they
 * cannot be revealed, edited, given or put in a giveaway.
 *
 * Accepting (the receiver's vault must be open) opens each seal and
 * stores the code in the receiver's vault as a new, sealed key: its code
 * was never shown to them, the global reveal skips it, and only an
 * explicit reveal (logged) shows it. In the same transaction the
 * sender's code is wiped and their key becomes "transferred", so the
 * sender can no longer read it here. Declining, cancelling or expiry
 * deletes the sealed copies and frees the sender's keys.
 *
 * Who has seen a code is kept per lineage in key_reveals (append-only):
 * whoever entered it, every explicit reveal, and the viewer who claimed
 * it as a prize. People are always shown by handle: their Twitch channel,
 * or their StreamOrg login when they have none; never names or e-mails.
 */
final class KeyTransfers
{
    public const MAX_KEYS = 50;

    public const EXPIRES_DAYS = 14;

    public const PER_DAY = 30;

    public const MESSAGE_MAX = 300;

    /** Key statuses that can be sent. */
    public const SENDABLE = ['available', 'for_giveaway'];

    private const CIPHER = 'aes-256-gcm';

    /** SQL for a user's public handle: the Twitch channel, or the login. */
    public static function handleSql(string $alias): string
    {
        return "coalesce(CASE WHEN {$alias}.channel_platform_id = (SELECT id FROM streaming_platforms WHERE code = 'twitch')
                              THEN nullif({$alias}.channel_handle, '') END, {$alias}.username::text)";
    }

    public static function handleOf(int $userId): string
    {
        $stmt = Database::connection()->prepare('SELECT ' . self::handleSql('u') . ' FROM users u WHERE u.id = ?');
        $stmt->execute([$userId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * The creator a handle points to: an active account other than the
     * sender's, by its Twitch channel or else its login. A handle shared
     * by two accounts is resolved by login, or refused.
     *
     * @return array{id:int, handle:string}
     * @throws UserError
     */
    public static function recipient(string $handle, int $fromUserId): array
    {
        $handle = strtolower(ltrim(trim($handle), '@'));

        if (!preg_match('/^[a-z0-9_.-]{2,32}$/', $handle)) {
            throw new UserError(__('ui.message.transfer_no_recipient'));
        }

        $stmt = Database::connection()->prepare(
            'SELECT u.id, ' . self::handleSql('u') . ' AS handle, lower(u.username::text) = ? AS by_login
               FROM users u
              WHERE u.is_active AND u.signup_status IS NULL
                AND (lower(' . self::handleSql('u') . ') = ? OR lower(u.username::text) = ?)'
        );
        $stmt->execute([$handle, $handle, $handle]);
        $found = $stmt->fetchAll();

        if (count($found) > 1) {
            $found = array_values(array_filter($found, static fn (array $u): bool => (bool) $u['by_login']));
        }

        if (count($found) !== 1) {
            throw new UserError(__('ui.message.transfer_no_recipient'));
        }

        if ((int) $found[0]['id'] === $fromUserId) {
            throw new UserError(__('ui.message.transfer_to_self'));
        }

        return ['id' => (int) $found[0]['id'], 'handle' => (string) $found[0]['handle']];
    }

    /**
     * Creators to send keys to, for the picker: active accounts other than
     * the searcher's whose handle starts with what was typed. Only the
     * handle is matched and returned (with the picture), never a name or
     * an e-mail, nor the login behind a Twitch handle.
     *
     * @return list<array{handle:string, twitch:bool, avatar:?string}>
     */
    public static function search(int $userId, string $term): array
    {
        $term = strtolower(ltrim(trim($term), '@'));

        if (!preg_match('/^[a-z0-9_.-]{2,32}$/', $term)) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT x.handle, x.twitch, x.avatar_path, x.avatar_updated_at FROM (
                 SELECT ' . self::handleSql('u') . " AS handle,
                        (u.channel_platform_id = (SELECT id FROM streaming_platforms WHERE code = 'twitch') AND coalesce(u.channel_handle, '') <> '') AS twitch,
                        u.avatar_path, u.avatar_updated_at
                   FROM users u
                  WHERE u.is_active AND u.signup_status IS NULL AND u.id <> ?
             ) x
             WHERE lower(x.handle) LIKE ? ESCAPE '\\'
          ORDER BY lower(x.handle) = ? DESC, length(x.handle), x.handle
             LIMIT 8"
        );
        $stmt->execute([$userId, str_replace(['_', '%'], ['\\_', '\\%'], $term) . '%', $term]);

        return array_map(static fn (array $u): array => [
            'handle' => (string) $u['handle'],
            'twitch' => (bool) $u['twitch'],
            'avatar' => Avatars::url($u),
        ], $stmt->fetchAll());
    }

    /**
     * The keys a creator can send: available or marked for a giveaway,
     * with a real code, not a prize and not already on the way.
     *
     * @return list<array<string, mixed>>
     */
    public static function sendable(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT k.id, k.status, k.key_type, k.content_type, k.code_seen, k.origin, g.title, gp.code AS platform_code, kp.code AS source_code
               FROM game_keys k
               JOIN games g ON g.id = k.game_id
               JOIN game_platforms gp ON gp.id = k.game_platform_id
               JOIN key_platforms kp ON kp.id = k.key_platform_id
              WHERE k.user_id = ? AND k.status IN ('available', 'for_giveaway') AND NOT k.is_placeholder
                AND k.transfer_item_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM giveaway_prizes p WHERE p.game_key_id = k.id)
           ORDER BY g.title, gp.code, k.id"
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * Sends keys to another creator: each code is sealed for the transfer
     * and the keys are locked until it is decided.
     *
     * @param list<int> $keyIds
     * @return array{id:int, handle:string, count:int}
     * @throws UserError|VaultLocked
     */
    public static function send(int $fromUserId, string $handle, array $keyIds, string $message = ''): array
    {
        $keyIds  = array_values(array_unique(array_filter(array_map('intval', $keyIds), static fn (int $id): bool => $id > 0)));
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        if ($keyIds === []) {
            throw new UserError(__('ui.message.transfer_no_keys'));
        }
        if (count($keyIds) > self::MAX_KEYS) {
            throw new UserError(sprintf(__('ui.message.transfer_too_many'), self::MAX_KEYS));
        }
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            throw new UserError(sprintf(__('ui.message.transfer_message_long'), self::MESSAGE_MAX));
        }

        $to = self::recipient($handle, $fromUserId);

        $sent = Database::connection()->prepare("SELECT count(*) FROM key_transfers WHERE from_user_id = ? AND created_at > now() - interval '1 day'");
        $sent->execute([$fromUserId]);

        if ((int) $sent->fetchColumn() >= self::PER_DAY) {
            throw new UserError(__('ui.message.transfer_rate'));
        }

        if (!Vault::isUnlocked($fromUserId)) {
            throw new VaultLocked();
        }

        $fromHandle = self::handleOf($fromUserId);

        $transferId = Database::transaction(static function (PDO $pdo) use ($fromUserId, $fromHandle, $to, $keyIds, $message): int {
            $stmt = $pdo->prepare(
                'SELECT k.*, g.title, EXISTS (SELECT 1 FROM giveaway_prizes p WHERE p.game_key_id = k.id) AS in_prize
                   FROM game_keys k JOIN games g ON g.id = k.game_id
                  WHERE k.user_id = ? AND k.id = ANY(CAST(? AS bigint[]))
                    FOR UPDATE OF k'
            );
            $stmt->execute([$fromUserId, '{' . implode(',', $keyIds) . '}']);
            $keys = $stmt->fetchAll();

            if (count($keys) !== count($keyIds)) {
                throw new UserError(__('ui.message.not_found'));
            }

            $codes = [];

            foreach ($keys as $key) {
                if (!in_array($key['status'], self::SENDABLE, true) || $key['in_prize'] || $key['is_placeholder'] || $key['transfer_item_id'] !== null) {
                    throw new UserError(sprintf(__('ui.message.transfer_key_unavailable'), $key['title']));
                }

                $plain = Vault::decryptCode($fromUserId, (string) $key['key_code']);

                if ($plain === null || trim($plain) === '') {
                    throw new UserError(sprintf(__('ui.message.transfer_key_unreadable'), $key['title']));
                }

                $codes[(int) $key['id']] = $plain;
            }

            $lock = random_bytes(32);
            $stmt = $pdo->prepare(
                'INSERT INTO key_transfers (from_user_id, to_user_id, from_handle, to_handle, message, lock_key, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, now() + make_interval(days => ?)) RETURNING id'
            );
            $stmt->execute([$fromUserId, $to['id'], $fromHandle, $to['handle'], $message === '' ? null : $message,
                            Crypto::encrypt(base64_encode($lock)), self::EXPIRES_DAYS]);
            $transferId = (int) $stmt->fetchColumn();

            $item = $pdo->prepare(
                'INSERT INTO key_transfer_items (transfer_id, from_key_id, lineage, game_id, game_platform_id, key_platform_id, key_type, content_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
            );
            $seal  = $pdo->prepare('UPDATE key_transfer_items SET sealed_code = ? WHERE id = ?');
            $hold  = $pdo->prepare('UPDATE game_keys SET transfer_item_id = ?, updated_at = now() WHERE id = ?');
            $known = $pdo->prepare('SELECT 1 FROM key_reveals WHERE lineage = ? AND user_id = ? LIMIT 1');

            foreach ($keys as $key) {
                $item->execute([$transferId, $key['id'], $key['lineage'], $key['game_id'], $key['game_platform_id'], $key['key_platform_id'], $key['key_type'], $key['content_type']]);
                $itemId = (int) $item->fetchColumn();

                $seal->execute([self::seal($lock, $transferId, $itemId, $codes[(int) $key['id']]), $itemId]);
                $hold->execute([$itemId, $key['id']]);

                $known->execute([$key['lineage'], $fromUserId]);

                if ($key['code_seen'] && $known->fetchColumn() === false) {
                    self::log((string) $key['lineage'], $fromUserId, $fromHandle, 'entered', (string) $key['created_at']);
                }
            }

            return $transferId;
        });

        Notifications::system([$to['id']], 'key_transfer_received', [$fromHandle, (string) count($keyIds)], '/keys/transfers', 'news');

        return ['id' => $transferId, 'handle' => $to['handle'], 'count' => count($keyIds)];
    }

    /**
     * The receiver takes the keys: each code goes into their vault as a
     * new sealed key, and the sender's copy is wiped. A code the receiver
     * already has goes back to the sender instead.
     *
     * @return array{moved:int, duplicates:list<string>}
     * @throws UserError|VaultLocked
     */
    public static function accept(int $toUserId, int $transferId): array
    {
        self::expireStale();

        if (!Vault::isUnlocked($toUserId)) {
            throw new VaultLocked();
        }

        $result = Database::transaction(static function (PDO $pdo) use ($toUserId, $transferId): array {
            $transfer = self::lockPending($pdo, $transferId, 'to_user_id', $toUserId);
            $lock     = self::lockOf($transfer);

            $stmt = $pdo->prepare(
                'SELECT i.*, g.title FROM key_transfer_items i JOIN games g ON g.id = i.game_id
                  WHERE i.transfer_id = ? ORDER BY i.id'
            );
            $stmt->execute([$transferId]);
            $items = $stmt->fetchAll();

            $source = $pdo->prepare('SELECT * FROM game_keys WHERE id = ? AND transfer_item_id = ? FOR UPDATE');
            $back   = $pdo->prepare("UPDATE game_keys SET key_hash = NULL WHERE user_id = ? AND game_platform_id = ? AND key_hash = ? AND status = 'transferred'");
            $twin   = $pdo->prepare('SELECT 1 FROM game_keys WHERE user_id = ? AND game_platform_id = ? AND key_hash = ?');
            $insert = $pdo->prepare(
                "INSERT INTO game_keys (user_id, game_id, key_platform_id, game_platform_id, key_code, key_hash, key_type, content_type,
                                        status, region, expires_at, received_at, lineage, origin, code_seen, received_item_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'available', ?, ?, now(), ?, 'transfer', false, ?) RETURNING id"
            );
            $wipe   = $pdo->prepare("UPDATE game_keys SET status = 'transferred', key_code = '', transfer_item_id = NULL, updated_at = now() WHERE id = ?");
            $free   = $pdo->prepare('UPDATE game_keys SET transfer_item_id = NULL, updated_at = now() WHERE id = ?');
            $done   = $pdo->prepare('UPDATE key_transfer_items SET to_key_id = ?, sealed_code = NULL, outcome = ? WHERE id = ?');

            $moved      = 0;
            $duplicates = [];

            foreach ($items as $item) {
                $source->execute([$item['from_key_id'], $item['id']]);
                $key  = $source->fetch();
                $code = $item['sealed_code'] === null ? null : self::open($lock, $transferId, (int) $item['id'], (string) $item['sealed_code']);

                if ($key === false || $code === null) {
                    throw new UserError(__('ui.message.transfer_broken'));
                }

                $hash = Vault::hashCode($toUserId, $code);
                $back->execute([$toUserId, $key['game_platform_id'], $hash]);
                $twin->execute([$toUserId, $key['game_platform_id'], $hash]);

                if ($twin->fetchColumn() !== false) {
                    $free->execute([$key['id']]);
                    $done->execute([null, 'duplicate', $item['id']]);
                    $duplicates[] = (string) $item['title'];
                    continue;
                }

                $insert->execute([
                    $toUserId, $key['game_id'], $key['key_platform_id'], $key['game_platform_id'],
                    Vault::encryptCode($toUserId, $code), $hash, $key['key_type'], $key['content_type'],
                    $key['region'], $key['expires_at'], $key['lineage'], $item['id'],
                ]);
                $newId = (int) $insert->fetchColumn();

                $wipe->execute([$key['id']]);
                $done->execute([$newId, 'moved', $item['id']]);
                $moved++;
            }

            $pdo->prepare("UPDATE key_transfers SET status = 'accepted', decided_at = now(), lock_key = NULL WHERE id = ?")->execute([$transferId]);

            return ['moved' => $moved, 'duplicates' => $duplicates, 'from' => $transfer['from_user_id'], 'handle' => (string) $transfer['to_handle']];
        });

        if ($result['from'] !== null) {
            Notifications::system([(int) $result['from']], 'key_transfer_accepted', [$result['handle'], (string) $result['moved']], '/keys/transfers', 'success');
        }

        return ['moved' => $result['moved'], 'duplicates' => $result['duplicates']];
    }

    /** The receiver turns the keys down: they go back to the sender. */
    public static function decline(int $toUserId, int $transferId): bool
    {
        return self::close($transferId, 'to_user_id', $toUserId, 'declined');
    }

    /** The sender takes the keys back before they are accepted. */
    public static function cancel(int $fromUserId, int $transferId): bool
    {
        return self::close($transferId, 'from_user_id', $fromUserId, 'cancelled');
    }

    /** Ends the transfers nobody accepted in time; their keys go back. */
    public static function expireStale(): int
    {
        $stmt = Database::connection()->query("SELECT id FROM key_transfers WHERE status = 'pending' AND expires_at <= now()");
        $count = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $count += (int) self::close((int) $id, null, null, 'expired');
        }

        return $count;
    }

    /**
     * A user's transfers, sent and received, newest first, each with its
     * keys and, per key, who had seen its code by then.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId): array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT t.id, t.status, t.message, t.created_at, t.expires_at, t.decided_at,
                    (t.to_user_id = ?) AS incoming,
                    coalesce((SELECT ' . self::handleSql('u') . ' FROM users u WHERE u.id = t.from_user_id), t.from_handle) AS from_handle,
                    coalesce((SELECT ' . self::handleSql('u') . ' FROM users u WHERE u.id = t.to_user_id), t.to_handle) AS to_handle
               FROM key_transfers t
              WHERE t.from_user_id = ? OR t.to_user_id = ?
           ORDER BY t.status = \'pending\' DESC, t.created_at DESC, t.id DESC
              LIMIT 300'
        );
        $stmt->execute([$userId, $userId, $userId]);
        $transfers = $stmt->fetchAll();

        if ($transfers === []) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT i.id, i.transfer_id, i.lineage, i.outcome, i.key_type, i.content_type, i.from_key_id, i.to_key_id,
                    g.title, gp.code AS platform_code, kp.code AS source_code
               FROM key_transfer_items i
               JOIN games g ON g.id = i.game_id
               JOIN game_platforms gp ON gp.id = i.game_platform_id
               JOIN key_platforms kp ON kp.id = i.key_platform_id
              WHERE i.transfer_id = ANY(CAST(? AS bigint[]))
           ORDER BY g.title, i.id'
        );
        $stmt->execute(['{' . implode(',', array_column($transfers, 'id')) . '}']);

        $items = [];

        foreach ($stmt->fetchAll() as $item) {
            $items[(int) $item['transfer_id']][] = $item;
        }

        foreach ($transfers as &$transfer) {
            $until = $transfer['decided_at'] ?? null;
            $transfer['items'] = array_map(static fn (array $item): array => $item + [
                'seen' => self::seenBy((string) $item['lineage'], $until),
            ], $items[(int) $transfer['id']] ?? []);
        }

        return $transfers;
    }

    /** @return int transfers waiting for this user's answer */
    public static function waitingFor(int $userId): int
    {
        $stmt = Database::connection()->prepare("SELECT count(*) FROM key_transfers WHERE to_user_id = ? AND status = 'pending' AND expires_at > now()");
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Who has seen a code, oldest first, up to a moment (null: until now).
     *
     * @return list<array{handle:string, kind:string, happened_at:string}>
     */
    public static function seenBy(string $lineage, ?string $until = null): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT handle, kind, happened_at FROM key_reveals
              WHERE lineage = ? AND (CAST(? AS timestamptz) IS NULL OR happened_at <= CAST(? AS timestamptz))
           ORDER BY happened_at, id'
        );
        $stmt->execute([$lineage, $until, $until]);

        return $stmt->fetchAll();
    }

    /**
     * A key's history, for its owner: the creators it passed through
     * (by handle, with when each received it) and who has seen its code.
     * For a key the owner passed on, the history stops at that hand-over.
     *
     * @return array{holders:list<array{handle:string, since:?string}>, seen:list<array>}|null null for a key that never moved
     */
    public static function history(int $userId, int $keyId): ?array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare('SELECT lineage, status, origin FROM game_keys WHERE id = ? AND user_id = ?');
        $stmt->execute([$keyId, $userId]);
        $key = $stmt->fetch();

        if ($key === false) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT t.from_user_id, t.to_user_id, t.decided_at,
                    coalesce((SELECT " . self::handleSql('u') . " FROM users u WHERE u.id = t.from_user_id), t.from_handle) AS from_handle,
                    coalesce((SELECT " . self::handleSql('u') . " FROM users u WHERE u.id = t.to_user_id), t.to_handle) AS to_handle
               FROM key_transfer_items i JOIN key_transfers t ON t.id = i.transfer_id
              WHERE i.lineage = ? AND i.outcome = 'moved'
           ORDER BY t.decided_at, t.id"
        );
        $stmt->execute([$key['lineage']]);
        $moves = $stmt->fetchAll();

        if ($moves === [] && $key['origin'] === 'own') {
            return null;
        }

        $holders = [];
        $until   = null;

        foreach ($moves as $i => $move) {
            if ($i === 0) {
                $holders[] = ['handle' => (string) $move['from_handle'], 'since' => null];
            }

            $holders[] = ['handle' => (string) $move['to_handle'], 'since' => (string) $move['decided_at']];

            if ((int) $move['from_user_id'] === $userId && $key['status'] === 'transferred') {
                $until = (string) $move['decided_at'];
                break;
            }
        }

        return ['holders' => $holders, 'seen' => self::seenBy((string) $key['lineage'], $until)];
    }

    /**
     * Shows a sealed key's code to its owner, once they confirmed it:
     * the key is no longer sealed, and the reveal is logged for good.
     */
    public static function revealSealed(int $userId, int $keyId): ?string
    {
        return Database::transaction(static function (PDO $pdo) use ($userId, $keyId): ?string {
            $stmt = $pdo->prepare("SELECT * FROM game_keys WHERE id = ? AND user_id = ? AND status <> 'transferred' AND transfer_item_id IS NULL FOR UPDATE");
            $stmt->execute([$keyId, $userId]);
            $key = $stmt->fetch();

            if ($key === false) {
                return null;
            }

            $plain = Vault::decryptCode($userId, (string) $key['key_code']);

            if ($plain === null || $plain === '') {
                return null;
            }

            if (!$key['code_seen']) {
                $pdo->prepare('UPDATE game_keys SET code_seen = true, updated_at = now() WHERE id = ?')->execute([$keyId]);
                self::log((string) $key['lineage'], $userId, self::handleOf($userId), 'revealed');
            }

            return $plain;
        });
    }

    /** Records that a viewer took a key's code as a prize. */
    public static function logClaim(int $keyId, string $viewerLogin): void
    {
        $stmt = Database::connection()->prepare('SELECT lineage FROM game_keys WHERE id = ?');
        $stmt->execute([$keyId]);
        $lineage = $stmt->fetchColumn();

        if ($lineage !== false) {
            self::log((string) $lineage, null, $viewerLogin, 'claimed');
        }
    }

    private static function log(string $lineage, ?int $userId, string $handle, string $kind, ?string $at = null): void
    {
        Database::connection()->prepare('INSERT INTO key_reveals (lineage, user_id, handle, kind, happened_at) VALUES (?, ?, ?, ?, coalesce(CAST(? AS timestamptz), now()))')
            ->execute([$lineage, $userId, $handle, $kind, $at]);
    }

    /**
     * Ends a pending transfer without moving anything: the sealed copies
     * and the lock are deleted and the sender's keys are free again.
     */
    private static function close(int $transferId, ?string $column, ?int $userId, string $status): bool
    {
        $result = Database::transaction(static function (PDO $pdo) use ($transferId, $column, $userId, $status): ?array {
            try {
                $transfer = self::lockPending($pdo, $transferId, $column, $userId, $status !== 'expired');
            } catch (UserError) {
                return null;
            }

            $pdo->prepare('UPDATE game_keys SET transfer_item_id = NULL, updated_at = now() WHERE transfer_item_id IN (SELECT id FROM key_transfer_items WHERE transfer_id = ?)')
                ->execute([$transferId]);
            $pdo->prepare("UPDATE key_transfer_items SET sealed_code = NULL, outcome = 'returned' WHERE transfer_id = ?")->execute([$transferId]);
            $pdo->prepare('UPDATE key_transfers SET status = ?, decided_at = now(), lock_key = NULL WHERE id = ?')->execute([$status, $transferId]);

            return $transfer;
        });

        if ($result === null) {
            return false;
        }

        $notify = match ($status) {
            'declined'  => [$result['from_user_id'], 'key_transfer_declined', (string) $result['to_handle']],
            'cancelled' => [$result['to_user_id'], 'key_transfer_cancelled', (string) $result['from_handle']],
            default     => [$result['from_user_id'], 'key_transfer_expired', (string) $result['to_handle']],
        };

        if ($notify[0] !== null) {
            Notifications::system([(int) $notify[0]], $notify[1], [$notify[2]], '/keys/transfers', 'info');
        }

        return true;
    }

    /**
     * A pending transfer, locked for the change; by its party when
     * $column is given. Expired ones count as gone unless $live is false.
     *
     * @throws UserError when there is no such pending transfer
     */
    private static function lockPending(PDO $pdo, int $transferId, ?string $column, ?int $userId, bool $live = true): array
    {
        $sql    = "SELECT * FROM key_transfers WHERE id = ? AND status = 'pending'" . ($live ? ' AND expires_at > now()' : '');
        $params = [$transferId];

        if ($column !== null) {
            $sql     .= ' AND ' . ($column === 'to_user_id' ? 'to_user_id' : 'from_user_id') . ' = ?';
            $params[] = $userId;
        }

        $stmt = $pdo->prepare($sql . ' FOR UPDATE');
        $stmt->execute($params);
        $transfer = $stmt->fetch();

        if ($transfer === false) {
            throw new UserError(__('ui.message.transfer_gone'));
        }

        return $transfer;
    }

    private static function lockOf(array $transfer): string
    {
        $key = base64_decode((string) Crypto::decrypt((string) $transfer['lock_key']), true);

        if (!is_string($key) || strlen($key) !== 32) {
            throw new UserError(__('ui.message.transfer_broken'));
        }

        return $key;
    }

    private static function seal(string $lock, int $transferId, int $itemId, string $plain): string
    {
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $lock, OPENSSL_RAW_DATA, $iv, $tag, "transfer:{$transferId}:item:{$itemId}");

        if ($cipher === false) {
            throw new RuntimeException('Sealing failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    private static function open(string $lock, int $transferId, int $itemId, string $sealed): ?string
    {
        $raw = base64_decode($sealed, true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $lock, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), "transfer:{$transferId}:item:{$itemId}");

        return $plain === false ? null : $plain;
    }
}
