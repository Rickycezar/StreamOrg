<?php
declare(strict_types=1);

/**
 * Keys passed between creators' vaults: sealed on the way, locked at the
 * sender, wiped from the sender once accepted, sealed at the receiver
 * until an explicit, logged reveal; and who has seen each code.
 */
final class KeyTransfersTest extends DatabaseTestCase
{
    private int $alice;
    private int $bob;
    private int $carol;
    private int $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser('phpunit_alice');
        $this->bob   = $this->createUser('phpunit_bob');
        $this->carol = $this->createUser('phpunit_carol');

        $this->pdo->prepare("UPDATE users SET display_name = 'Alice Real Name', channel_handle = 'alice_tv',
                                    channel_platform_id = (SELECT id FROM streaming_platforms WHERE code = 'twitch') WHERE id = ?")
            ->execute([$this->alice]);

        $stmt = $this->pdo->prepare('INSERT INTO games (title, slug) VALUES (?, ?) RETURNING id');
        $stmt->execute(['PHPUnit Transfer', 'phpunit-transfer-' . bin2hex(random_bytes(3))]);
        $this->game = (int) $stmt->fetchColumn();
    }

    private function key(int $userId, string $code, string $status = 'available'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_keys (user_id, game_id, key_platform_id, game_platform_id, key_code, key_hash, status)
             VALUES (?, ?, (SELECT id FROM key_platforms ORDER BY id LIMIT 1), (SELECT id FROM game_platforms ORDER BY id LIMIT 1), ?, ?, ?)
             RETURNING id"
        );
        $stmt->execute([$userId, $this->game, Vault::encryptCode($userId, $code), Vault::hashCode($userId, $code), $status]);

        return (int) $stmt->fetchColumn();
    }

    private function row(int $keyId): array
    {
        return $this->pdo->query("SELECT * FROM game_keys WHERE id = {$keyId}")->fetch();
    }

    private function received(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM game_keys WHERE user_id = ? AND origin = 'transfer' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);

        return $stmt->fetch();
    }

    public function testAcceptedKeysMoveSealedAndLeaveTheSenderWithoutTheCode(): void
    {
        $key  = $this->key($this->alice, 'AAAA-1111');
        $sent = KeyTransfers::send($this->alice, '@phpunit_bob', [$key], 'For your stream');

        self::assertSame('phpunit_bob', $sent['handle']);
        self::assertNotNull($this->row($key)['transfer_item_id'], 'The key is locked while the transfer waits.');
        $sealed = (string) $this->pdo->query("SELECT sealed_code FROM key_transfer_items WHERE transfer_id = {$sent['id']}")->fetchColumn();
        self::assertStringNotContainsString('AAAA', base64_decode($sealed), 'The code travels sealed.');

        $result = KeyTransfers::accept($this->bob, $sent['id']);
        self::assertSame(1, $result['moved']);

        $old = $this->row($key);
        self::assertSame('transferred', $old['status']);
        self::assertSame('', $old['key_code'], 'The sender keeps no copy of the code.');
        self::assertNull($old['transfer_item_id']);

        $new = $this->received($this->bob);
        self::assertSame('AAAA-1111', Vault::decryptCode($this->bob, (string) $new['key_code']));
        self::assertFalse((bool) $new['code_seen'], 'It arrives sealed.');
        self::assertSame($old['lineage'], $new['lineage']);

        $transfer = $this->pdo->query("SELECT status, lock_key FROM key_transfers WHERE id = {$sent['id']}")->fetch();
        self::assertSame('accepted', $transfer['status']);
        self::assertNull($transfer['lock_key']);
        self::assertNull($this->pdo->query("SELECT sealed_code FROM key_transfer_items WHERE transfer_id = {$sent['id']}")->fetchColumn());
    }

    public function testAKeyOnItsWayCannotBeGivenSentAgainOrPutInAGiveaway(): void
    {
        $key = $this->key($this->alice, 'LOCK-0001');
        KeyTransfers::send($this->alice, 'phpunit_bob', [$key]);

        self::assertNotContains($key, array_map('intval', array_column(Giveaways::giftableKeys($this->alice), 'id')));
        self::assertNotContains($key, array_map('intval', array_column(KeyTransfers::sendable($this->alice), 'id')));

        $this->expectException(UserError::class);
        KeyTransfers::send($this->alice, 'phpunit_carol', [$key]);
    }

    public function testDecliningCancellingAndExpiryGiveTheKeysBack(): void
    {
        foreach (['decline', 'cancel', 'expire'] as $how) {
            $key  = $this->key($this->alice, 'BACK-' . $how);
            $sent = KeyTransfers::send($this->alice, 'phpunit_bob', [$key]);

            if ($how === 'decline') {
                self::assertFalse(KeyTransfers::decline($this->alice, $sent['id']), 'Only the receiver declines.');
                self::assertTrue(KeyTransfers::decline($this->bob, $sent['id']));
            } elseif ($how === 'cancel') {
                self::assertFalse(KeyTransfers::cancel($this->bob, $sent['id']), 'Only the sender cancels.');
                self::assertTrue(KeyTransfers::cancel($this->alice, $sent['id']));
            } else {
                $this->pdo->exec("UPDATE key_transfers SET expires_at = now() - interval '1 minute' WHERE id = {$sent['id']}");
                self::assertGreaterThanOrEqual(1, KeyTransfers::expireStale());
            }

            $row = $this->row($key);
            self::assertNull($row['transfer_item_id'], $how);
            self::assertSame('BACK-' . $how, Vault::decryptCode($this->alice, (string) $row['key_code']), $how);
            self::assertNull($this->pdo->query("SELECT sealed_code FROM key_transfer_items WHERE transfer_id = {$sent['id']}")->fetchColumn(), $how);
        }

        $this->expectException(UserError::class);
        KeyTransfers::accept($this->bob, $sent['id']);
    }

    public function testRevealsAreLoggedAlongTheChainByHandle(): void
    {
        $key = $this->key($this->alice, 'CHAIN-0001');
        KeyTransfers::accept($this->bob, KeyTransfers::send($this->alice, 'phpunit_bob', [$key])['id']);
        $atBob = (int) $this->received($this->bob)['id'];

        self::assertSame('CHAIN-0001', KeyTransfers::revealSealed($this->bob, $atBob));
        KeyTransfers::revealSealed($this->bob, $atBob);

        KeyTransfers::accept($this->carol, KeyTransfers::send($this->bob, 'phpunit_carol', [$atBob])['id']);
        $atCarol = (int) $this->received($this->carol)['id'];

        $history = KeyTransfers::history($this->carol, $atCarol);
        self::assertSame(['alice_tv', 'phpunit_bob', 'phpunit_carol'], array_column($history['holders'], 'handle'));
        self::assertSame([['alice_tv', 'entered'], ['phpunit_bob', 'revealed']],
            array_map(static fn (array $r): array => [$r['handle'], $r['kind']], $history['seen']), 'One reveal is logged once; nobody is named, only handled.');

        $aliceView = KeyTransfers::history($this->alice, $key);
        self::assertSame(['alice_tv', 'phpunit_bob'], array_column($aliceView['holders'], 'handle'), 'A past holder sees the chain only up to their hand-over.');
    }

    public function testAForwardedSealedKeyStaysUnseen(): void
    {
        $key = $this->key($this->alice, 'SEAL-0001');
        KeyTransfers::accept($this->bob, KeyTransfers::send($this->alice, 'phpunit_bob', [$key])['id']);
        $atBob = (int) $this->received($this->bob)['id'];

        KeyTransfers::accept($this->carol, KeyTransfers::send($this->bob, 'phpunit_carol', [$atBob])['id']);

        $seen = KeyTransfers::history($this->carol, (int) $this->received($this->carol)['id'])['seen'];
        self::assertSame(['alice_tv'], array_column($seen, 'handle'));
    }

    public function testTheRevealLogCannotBeChanged(): void
    {
        KeyTransfers::send($this->alice, 'phpunit_bob', [$this->key($this->alice, 'LOG-0001')]);

        foreach (['UPDATE key_reveals SET handle = \'someone\'', 'DELETE FROM key_reveals'] as $sql) {
            $this->pdo->exec('SAVEPOINT tamper');

            try {
                $this->pdo->exec($sql);
                self::fail('The log accepted: ' . $sql);
            } catch (PDOException $e) {
                self::assertStringContainsString('append-only', $e->getMessage());
            }

            $this->pdo->exec('ROLLBACK TO SAVEPOINT tamper');
        }
    }

    public function testACodeTheReceiverAlreadyHasGoesBack(): void
    {
        $this->key($this->bob, 'DUP-0001');
        $key = $this->key($this->alice, 'DUP-0001');

        $result = KeyTransfers::accept($this->bob, KeyTransfers::send($this->alice, 'phpunit_bob', [$key])['id']);

        self::assertSame(0, $result['moved']);
        self::assertSame(['PHPUnit Transfer'], $result['duplicates']);
        self::assertSame('available', $this->row($key)['status']);
        self::assertNull($this->row($key)['transfer_item_id']);
    }

    public function testAKeyCanComeBackToAFormerHolder(): void
    {
        $key = $this->key($this->alice, 'ROUND-0001');
        KeyTransfers::accept($this->bob, KeyTransfers::send($this->alice, 'phpunit_bob', [$key])['id']);
        $result = KeyTransfers::accept($this->alice, KeyTransfers::send($this->bob, 'alice_tv', [(int) $this->received($this->bob)['id']])['id']);

        self::assertSame(1, $result['moved']);
        self::assertSame('ROUND-0001', Vault::decryptCode($this->alice, (string) $this->received($this->alice)['key_code']));
    }

    public function testRecipientsAreFoundByHandleOrLoginOnly(): void
    {
        self::assertSame($this->alice, KeyTransfers::recipient('@Alice_TV', $this->bob)['id']);
        self::assertSame('alice_tv', KeyTransfers::recipient('phpunit_alice', $this->bob)['handle'], 'The handle is the Twitch channel when there is one.');

        $refused = 0;
        $this->pdo->exec("UPDATE users SET is_active = false WHERE id = {$this->carol}");

        foreach (['Alice Real Name', 'phpunit_alice@example.com', 'phpunit_carol', 'nobody_here'] as $handle) {
            try {
                KeyTransfers::recipient($handle, $this->bob);
            } catch (UserError) {
                $refused++;
            }
        }

        self::assertSame(4, $refused, 'Not by name, e-mail, an inactive account or an unknown handle.');

        $this->expectException(UserError::class);
        KeyTransfers::recipient('alice_tv', $this->alice);
    }

    public function testTheCreatorSearchMatchesHandlesOnly(): void
    {
        self::assertSame(['alice_tv'], array_column(KeyTransfers::search($this->bob, '@ALICE'), 'handle'));
        self::assertTrue(KeyTransfers::search($this->bob, 'alice_')[0]['twitch']);
        self::assertSame([], KeyTransfers::search($this->bob, 'phpunit_ali'), 'Not by the login behind a Twitch handle.');
        self::assertSame([], KeyTransfers::search($this->bob, 'Alice Real'), 'Not by name.');
        self::assertSame([], KeyTransfers::search($this->bob, 'phpunit_alice@'), 'Not by e-mail.');
        self::assertSame([], KeyTransfers::search($this->bob, 'a'), 'At least two letters.');
        self::assertNotContains('phpunit_bob', array_column(KeyTransfers::search($this->bob, 'phpunit_'), 'handle'), 'Never yourself.');
        self::assertContains('phpunit_carol', array_column(KeyTransfers::search($this->bob, 'phpunit_'), 'handle'));
        self::assertSame([], KeyTransfers::search($this->bob, '%'), 'Wildcards are not search terms.');
    }

    public function testAClaimIsLoggedWithTheViewersLogin(): void
    {
        $key = $this->key($this->alice, 'CLAIM-0001', 'for_giveaway');
        $stmt = $this->pdo->prepare(
            "INSERT INTO giveaways (user_id, title, status, winner_mode, entry_method, claim_days, is_direct)
             VALUES (?, 'Gift', 'open', 'assigned', 'manual', 30, true) RETURNING *"
        );
        $stmt->execute([$this->alice]);
        $giveaway = $stmt->fetch();
        $this->pdo->exec("INSERT INTO giveaway_prizes (giveaway_id, game_key_id) VALUES ({$giveaway['id']}, {$key})");
        $token = (new ReflectionMethod(Giveaways::class, 'recordWinner'))->invoke(null, $giveaway, '777', 'viewer777', null, 'manual', null);
        $viewer = $this->pdo->query("INSERT INTO viewers (twitch_user_id, twitch_login) VALUES ('777', 'viewer777') RETURNING *")->fetch();

        self::assertSame('CLAIM-0001', Giveaways::claim($token, $viewer, null));

        $seen = KeyTransfers::seenBy((string) $this->row($key)['lineage']);
        self::assertSame([['viewer777', 'claimed']], array_map(static fn (array $r): array => [$r['handle'], $r['kind']], $seen));
    }

    public function testALockedVaultCannotReceive(): void
    {
        $sent = KeyTransfers::send($this->alice, 'phpunit_bob', [$this->key($this->alice, 'PRIV-0001')]);

        Vault::makePrivate($this->bob, 'bob-password-1');
        $_SESSION = [];
        $_COOKIE  = [];
        $this->newRequest();

        $this->expectException(VaultLocked::class);
        KeyTransfers::accept($this->bob, $sent['id']);
    }
}
