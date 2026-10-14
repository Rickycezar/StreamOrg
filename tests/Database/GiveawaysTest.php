<?php
declare(strict_types=1);

/** Prizes linked to vault keys, copied only when needed, claimed only by their winner, never twice. */
final class GiveawaysTest extends DatabaseTestCase
{
    private int $user;
    private int $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('phpunit_giver');
        $stmt = $this->pdo->prepare('INSERT INTO games (title, slug) VALUES (?, ?) RETURNING id');
        $stmt->execute(['PHPUnit Quest', 'phpunit-quest-' . bin2hex(random_bytes(3))]);
        $this->game = (int) $stmt->fetchColumn();
    }

    private function key(string $code, string $status = 'for_giveaway', bool $placeholder = false): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_keys (user_id, game_id, key_platform_id, game_platform_id, key_code, key_hash, status, is_placeholder)
             VALUES (?, ?, (SELECT id FROM key_platforms ORDER BY id LIMIT 1), (SELECT id FROM game_platforms ORDER BY id LIMIT 1), ?, ?, ?, ?)
             RETURNING id"
        );
        $stmt->execute([$this->user, $this->game, Vault::encryptCode($this->user, $code), Vault::hashCode($this->user, $code), $status, $placeholder ? 'true' : 'false']);

        return (int) $stmt->fetchColumn();
    }

    private function giveaway(string $mode = 'pick', int $days = 30): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO giveaways (user_id, title, keyword, winner_mode, claim_days, status) VALUES (?, 'Test', ?, ?, ?, 'open') RETURNING id"
        );
        $stmt->execute([$this->user, 'k' . bin2hex(random_bytes(3)), $mode, $days]);

        return (int) $stmt->fetchColumn();
    }

    private function winner(int $giveawayId, string $twitchId, ?int $prizeId = null): string
    {
        $giveaway = Giveaways::find($this->user, $giveawayId);

        return (new ReflectionMethod(Giveaways::class, 'recordWinner'))
            ->invoke(null, $giveaway, $twitchId, 'user' . $twitchId, null, 'manual', $prizeId);
    }

    private function viewer(string $twitchId): array
    {
        $stmt = $this->pdo->prepare("INSERT INTO viewers (twitch_user_id, twitch_login) VALUES (?, ?) RETURNING *");
        $stmt->execute([$twitchId, 'user' . $twitchId]);

        return $stmt->fetch();
    }

    private function prizeIds(int $giveawayId): array
    {
        return array_map('intval', $this->pdo->query("SELECT id FROM giveaway_prizes WHERE giveaway_id = {$giveawayId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testRealKeysForGiveawayOrAvailableBecomePrizes(): void
    {
        $g = $this->giveaway();
        $ok = $this->key('AAAA-1111');
        $available = $this->key('BBBB-2222', 'available');
        $placeholder = $this->key('CCCC-3333', 'for_giveaway', true);
        $used = $this->key('DDDD-4444', 'used');

        $result = Giveaways::addPrizes($this->user, $g, [$ok, $available, $placeholder, $used]);

        self::assertSame(2, $result['added']);
        self::assertCount(2, $result['skipped']);
        self::assertSame('for_giveaway', $this->pdo->query("SELECT status FROM game_keys WHERE id = {$available}")->fetchColumn(), 'An available key added as a prize is marked for giveaway.');
        self::assertNull($this->pdo->query("SELECT sealed_code FROM giveaway_prizes WHERE game_key_id = {$ok}")->fetchColumn(), 'Adding a prize must copy nothing.');
    }

    public function testGiftableKeysListThoseMarkedForGiveawayFirst(): void
    {
        $available = $this->key('AAAA-1111', 'available');
        $marked = $this->key('BBBB-2222');
        $this->key('CCCC-3333', 'for_giveaway', true);
        $this->key('DDDD-4444', 'used');
        $prize = $this->key('EEEE-5555');
        Giveaways::addPrizes($this->user, $this->giveaway(), [$prize]);

        self::assertSame([$marked, $available], array_map('intval', array_column(Giveaways::giftableKeys($this->user), 'id')));
    }

    /** A direct gift as giveDirect makes it, without asking Twitch: a hidden giveaway, one key, one assigned winner. */
    private function gift(int $keyId, string $twitchId): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO giveaways (user_id, title, status, winner_mode, entry_method, claim_days, is_direct)
             VALUES (?, 'PHPUnit Quest', 'open', 'assigned', 'manual', 30, true) RETURNING id"
        );
        $stmt->execute([$this->user]);
        $g = (int) $stmt->fetchColumn();
        $this->pdo->exec("INSERT INTO giveaway_prizes (giveaway_id, game_key_id) VALUES ({$g}, {$keyId})");

        return ['giveaway' => $g, 'token' => $this->winner($g, $twitchId)];
    }

    public function testADirectGiftIsClaimedLikeAPrizeAndThenFinished(): void
    {
        $key  = $this->key('GIFT-1111');
        $gift = $this->gift($key, '501');

        self::assertSame('GIFT-1111', Giveaways::claim($gift['token'], $this->viewer('501'), null));
        self::assertSame('finished', $this->pdo->query("SELECT status FROM giveaways WHERE id = {$gift['giveaway']}")->fetchColumn());
        self::assertSame('given_away', $this->pdo->query("SELECT status FROM game_keys WHERE id = {$key}")->fetchColumn());

        $given = Giveaways::given($this->user);
        self::assertCount(1, $given);
        self::assertTrue((bool) $given[0]['is_direct']);
        self::assertSame([$key, 'claimed'], [(int) $given[0]['key_id'], PrizeRemovals::stateOf($given[0])]);
    }

    public function testWithdrawingADirectGiftFreesItsKey(): void
    {
        $key  = $this->key('GIFT-2222', 'available');
        $gift = $this->gift($key, '502');
        $winner = (int) $this->pdo->query("SELECT id FROM giveaway_winners WHERE giveaway_id = {$gift['giveaway']}")->fetchColumn();

        self::assertTrue(Giveaways::cancelWinner($this->user, $winner));
        self::assertSame('cancelled', $this->pdo->query("SELECT status FROM giveaways WHERE id = {$gift['giveaway']}")->fetchColumn());
        self::assertContains($key, array_map('intval', array_column(Giveaways::giftableKeys($this->user), 'id')), 'The key can be given again.');
        self::assertNull(Giveaways::claimByToken($gift['token'])['prizes'][0] ?? null);
    }

    public function testOnlyKeysThatCanBeGivenAreGiven(): void
    {
        foreach ([$this->key('PLACE-1', 'for_giveaway', true), $this->key('USED-1', 'used')] as $key) {
            try {
                Giveaways::giveDirect($this->user, $key, 'someone', null);
                self::fail("Key {$key} was given.");
            } catch (UserError) {
            }
        }

        $this->expectException(UserError::class);
        Giveaways::giveDirect($this->createUser('phpunit_other'), $this->key('MINE-1'), 'someone', null);
    }

    public function testWinnerPicksAKeyAndBothRecordsAreMarked(): void
    {
        $g = $this->giveaway();
        $first = $this->key('AAAA-1111');
        $this->key('BBBB-2222');
        Giveaways::addPrizes($this->user, $g, [$first, $first + 1]);
        [$p1] = $this->prizeIds($g);

        $token = $this->winner($g, '1001');
        $code  = Giveaways::claim($token, $this->viewer('1001'), $p1);

        self::assertSame('AAAA-1111', $code);
        self::assertSame('given_away', $this->pdo->query("SELECT status FROM game_keys WHERE id = {$first}")->fetchColumn());
        self::assertNotNull($this->pdo->query("SELECT claimed_at FROM giveaway_prizes WHERE id = {$p1}")->fetchColumn());
    }

    public function testATakenKeyCannotBeTakenAgain(): void
    {
        $g = $this->giveaway();
        $k = $this->key('AAAA-1111');
        Giveaways::addPrizes($this->user, $g, [$k]);
        [$p1] = $this->prizeIds($g);

        Giveaways::claim($this->winner($g, '1001'), $this->viewer('1001'), $p1);

        $this->expectException(UserError::class);
        Giveaways::claim($this->winner($g, '1002'), $this->viewer('1002'), $p1);
    }

    public function testPickingAKeySomeoneJustTookSaysSo(): void
    {
        Lang::setLocale('en');
        $g = $this->giveaway();
        Giveaways::addPrizes($this->user, $g, [$this->key('AAAA-1111'), $this->key('BBBB-2222')]);
        [$p1] = $this->prizeIds($g);
        Giveaways::claim($this->winner($g, '1001'), $this->viewer('1001'), $p1);

        try {
            Giveaways::claim($this->winner($g, '1002'), $this->viewer('1002'), $p1);
            self::fail('The same key was claimed twice.');
        } catch (UserError $e) {
            self::assertSame(__('ui.message.claim_taken'), $e->getMessage());
        }
    }

    public function testTakingBackIsRememberedUntilMadeClaimableAgain(): void
    {
        $k = $this->key('PRIV-0001');
        Vault::makePrivate($this->user, 'phpunit-password-1');
        $g = $this->giveaway();
        Giveaways::addPrizes($this->user, $g, [$k]);
        Giveaways::makeClaimable($this->user, $g);
        Giveaways::takeBack($this->user, $g);

        self::assertNotNull(Giveaways::find($this->user, $g)['copies_taken_back_at']);

        Giveaways::makeClaimable($this->user, $g);
        self::assertNull(Giveaways::find($this->user, $g)['copies_taken_back_at']);
    }

    public function testOnlyTheWinnersAccountCanClaim(): void
    {
        $g = $this->giveaway();
        $k = $this->key('AAAA-1111');
        Giveaways::addPrizes($this->user, $g, [$k]);
        $token = $this->winner($g, '1001');

        try {
            Giveaways::claim($token, $this->viewer('9999'), $this->prizeIds($g)[0]);
            self::fail('Another account claimed the prize.');
        } catch (UserError) {
            self::assertSame('for_giveaway', $this->pdo->query("SELECT status FROM game_keys WHERE id = {$k}")->fetchColumn());
        }
    }

    public function testExpiredAndWithdrawnLinksDoNotWork(): void
    {
        $g = $this->giveaway();
        Giveaways::addPrizes($this->user, $g, [$this->key('AAAA-1111')]);
        $token = $this->winner($g, '1001');
        $this->pdo->exec("UPDATE giveaway_winners SET expires_at = now() - interval '1 minute'");

        try {
            Giveaways::claim($token, $this->viewer('1001'), $this->prizeIds($g)[0]);
            self::fail('An expired link worked.');
        } catch (UserError) {
        }

        self::assertTrue(Giveaways::extendWinner($this->user, (int) $this->pdo->query('SELECT max(id) FROM giveaway_winners')->fetchColumn()));
        self::assertTrue(Giveaways::cancelWinner($this->user, (int) $this->pdo->query('SELECT max(id) FROM giveaway_winners')->fetchColumn()));

        $this->expectException(UserError::class);
        Giveaways::claim($token, $this->viewer('1002') + ['twitch_user_id' => '1001'], $this->prizeIds($g)[0]);
    }

    public function testAssignedModeGivesTheSetAsidePrize(): void
    {
        $g = $this->giveaway('assigned');
        $a = $this->key('AAAA-1111');
        $b = $this->key('BBBB-2222');
        Giveaways::addPrizes($this->user, $g, [$a, $b]);
        [, $p2] = $this->prizeIds($g);

        $token = $this->winner($g, '1001', $p2);

        self::assertSame('BBBB-2222', Giveaways::claim($token, $this->viewer('1001'), null));
    }

    public function testDrawPicksOnlyEntrantsWhoHaveNotWon(): void
    {
        $g = $this->giveaway();
        $this->pdo->exec("INSERT INTO giveaway_entries (giveaway_id, twitch_user_id, twitch_login) VALUES ({$g}, '1', 'one'), ({$g}, '2', 'two')");

        $first  = Giveaways::draw($this->user, $g)['login'];
        $second = Giveaways::draw($this->user, $g)['login'];

        self::assertEqualsCanonicalizing(['one', 'two'], [$first, $second]);

        $this->expectException(UserError::class);
        Giveaways::draw($this->user, $g);
    }

    public function testRecoverableVaultKeysAreReadOnlyWhenClaimed(): void
    {
        $g = $this->giveaway();
        Giveaways::addPrizes($this->user, $g, [$this->key('AAAA-1111'), $this->key('BBBB-2222')]);
        [$p1, $p2] = $this->prizeIds($g);

        Giveaways::claim($this->winner($g, '1001'), $this->viewer('1001'), $p1);

        self::assertNotNull($this->pdo->query("SELECT sealed_code FROM giveaway_prizes WHERE id = {$p1}")->fetchColumn(), 'The claimed key is kept for its winner.');
        self::assertNull($this->pdo->query("SELECT sealed_code FROM giveaway_prizes WHERE id = {$p2}")->fetchColumn(), 'Unclaimed keys stay only in the vault.');
    }

    public function testPickingALockedKeyReservesItUntilTheKeysAreOpened(): void
    {
        Lang::setLocale('en');
        $k = $this->key('PRIV-0001');
        $this->key('PRIV-0002');
        Vault::makePrivate($this->user, 'phpunit-password-1');
        $g = $this->giveaway();
        Giveaways::addPrizes($this->user, $g, [$k, $k + 1]);
        [$p1] = $this->prizeIds($g);
        $token = $this->winner($g, '1001');

        self::assertNull(Giveaways::claim($token, $this->viewer('1001'), $p1), 'A locked key is reserved, not revealed.');
        self::assertSame('for_giveaway', $this->pdo->query("SELECT status FROM game_keys WHERE id = {$k}")->fetchColumn());

        try {
            Giveaways::claim($this->winner($g, '1002'), $this->viewer('1002'), $p1);
            self::fail('A reserved key was taken by someone else.');
        } catch (UserError $e) {
            self::assertSame(__('ui.message.claim_taken'), $e->getMessage());
        }

        Giveaways::makeClaimable($this->user, $g);
        $this->newRequest();
        $_SESSION = [];
        $_COOKIE  = [];

        self::assertFalse(Vault::isUnlocked($this->user));
        self::assertSame('PRIV-0001', Giveaways::claim($token, ['id' => null, 'twitch_user_id' => '1001'], null));
        self::assertSame('given_away', $this->pdo->query("SELECT status FROM game_keys WHERE id = {$k}")->fetchColumn());
    }

    public function testWaitingWinnersCountsThoseHoldingLockedKeys(): void
    {
        $k = $this->key('PRIV-0001');
        Vault::makePrivate($this->user, 'phpunit-password-1');
        $g = $this->giveaway();
        Giveaways::addPrizes($this->user, $g, [$k]);
        $this->winner($g, '1001');

        self::assertSame(['waiting' => 1, 'locked' => 1], Giveaways::waitingWinners($g));

        Giveaways::makeClaimable($this->user, $g);
        self::assertSame(['waiting' => 1, 'locked' => 0], Giveaways::waitingWinners($g));
    }

    public function testTakingBackDeletesTheCopies(): void
    {
        $k = $this->key('PRIV-0001');
        Vault::makePrivate($this->user, 'phpunit-password-1');
        $g = $this->giveaway();
        Giveaways::addPrizes($this->user, $g, [$k]);
        Giveaways::makeClaimable($this->user, $g);

        self::assertSame(1, Giveaways::takeBack($this->user, $g));
        self::assertNull($this->pdo->query("SELECT sealed_code FROM giveaway_prizes WHERE giveaway_id = {$g}")->fetchColumn());
    }

    public function testAssignedPrizeOfAPrivateVaultIsCopiedForItsWinnerOnly(): void
    {
        $a = $this->key('PRIV-AAAA');
        $b = $this->key('PRIV-BBBB');
        Vault::makePrivate($this->user, 'phpunit-password-1');
        $g = $this->giveaway('assigned');
        Giveaways::addPrizes($this->user, $g, [$a, $b]);
        [$p1, $p2] = $this->prizeIds($g);

        $this->winner($g, '1001', $p2);

        self::assertNull($this->pdo->query("SELECT sealed_code FROM giveaway_prizes WHERE id = {$p1}")->fetchColumn());
        self::assertNotNull($this->pdo->query("SELECT sealed_code FROM giveaway_prizes WHERE id = {$p2}")->fetchColumn());
    }

    public function testFinishingReturnsUnclaimedKeysAndEndsUnusedLinks(): void
    {
        $g = $this->giveaway();
        $a = $this->key('AAAA-1111');
        $b = $this->key('BBBB-2222');
        Giveaways::addPrizes($this->user, $g, [$a, $b]);
        [$p1] = $this->prizeIds($g);
        Giveaways::claim($this->winner($g, '1001'), $this->viewer('1001'), $p1);
        $this->winner($g, '1002');
        $this->pdo->exec("INSERT INTO giveaway_entries (giveaway_id, twitch_user_id, twitch_login) VALUES ({$g}, '5', 'five')");

        self::assertSame(1, Giveaways::finish($this->user, $g));

        self::assertSame('finished', $this->pdo->query("SELECT status FROM giveaways WHERE id = {$g}")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM giveaway_prizes WHERE giveaway_id = {$g}")->fetchColumn(), 'Only the claimed prize remains.');
        self::assertSame('for_giveaway', $this->pdo->query("SELECT status FROM game_keys WHERE id = {$b}")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM giveaway_winners WHERE giveaway_id = {$g} AND claimed_at IS NULL AND cancelled_at IS NULL")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM giveaway_entries WHERE giveaway_id = {$g}")->fetchColumn());
    }

    public function testEditingTheCodeRefreshesACopy(): void
    {
        $k = $this->key('OLD-CODE');
        Vault::makePrivate($this->user, 'phpunit-password-1');
        $g = $this->giveaway();
        Giveaways::addPrizes($this->user, $g, [$k]);
        Giveaways::makeClaimable($this->user, $g);

        Giveaways::refreshKey($this->user, $k, 'NEW-CODE');

        self::assertSame('NEW-CODE', Giveaways::claim($this->winner($g, '1001'), $this->viewer('1001'), $this->prizeIds($g)[0]));
    }

    public function testMyPrizesShowsClaimedCodesAndDeletingTheViewerAnonymises(): void
    {
        $g = $this->giveaway();
        Giveaways::addPrizes($this->user, $g, [$this->key('AAAA-1111')]);
        $viewer = $this->viewer('1001');
        Giveaways::claim($this->winner($g, '1001'), $viewer, $this->prizeIds($g)[0]);

        self::assertSame('AAAA-1111', Giveaways::prizesOf($viewer)[0]['code']);

        Viewers::delete($viewer);

        self::assertSame([], Giveaways::prizesOf($viewer));
        self::assertSame('deleted', $this->pdo->query("SELECT twitch_login FROM giveaway_winners WHERE giveaway_id = {$g}")->fetchColumn());
    }

    public function testThePublicPrizePageShowsWhatIsLeftAndWhoWonButNeverACode(): void
    {
        $giveawayId = $this->giveaway('assigned');
        Giveaways::addPrizes($this->user, $giveawayId, [$this->key('PUB-1'), $this->key('PUB-2')]);
        $this->winner($giveawayId, 'tw-pub', $this->prizeIds($giveawayId)[0]);

        $code = (string) $this->pdo->query("SELECT public_code FROM giveaways WHERE id = {$giveawayId}")->fetchColumn();
        $view = Giveaways::publicView($code);

        self::assertSame('Test', $view['giveaway']['title']);
        self::assertSame([null, 'usertw-pub'], array_column($view['prizes'], 'winner_name'), 'Prizes still to win first.');
        self::assertStringNotContainsString('PUB-', json_encode($view));
        self::assertStringEndsWith('/giveaway?g=' . $code, Giveaways::publicUrl($code, 'en'));

        $this->pdo->exec("UPDATE giveaways SET is_surprise = true, status = 'planned' WHERE id = {$giveawayId}");
        self::assertNull(Giveaways::publicView($code), 'A surprise stays hidden until it opens.');
        self::assertNull(Giveaways::publicView('not-a-code'));
    }

    public function testTheAnnouncementSaysHowToEnterOnlyWhileChatEntriesAreOpen(): void
    {
        $giveawayId = $this->giveaway();
        $this->pdo->exec("UPDATE users SET locale = 'en' WHERE id = {$this->user}");
        $this->pdo->exec("UPDATE giveaways SET entry_method = 'chat', keyword = 'xmas', title = 'Xmas keys' WHERE id = {$giveawayId}");
        $this->pdo->exec("UPDATE bot_account SET is_enabled = true, twitch_login = 'phpunit_bot', twitch_user_id = '1', command_prefix = '!' WHERE id = 1");
        ChatBot::setChannel($this->user, true);

        $text = Giveaways::announcement(Giveaways::find($this->user, $giveawayId));
        self::assertStringStartsWith('🎁 Xmas keys is on! Type !join xmas to enter! Prizes: ', $text);

        $this->pdo->exec("UPDATE giveaways SET status = 'closed' WHERE id = {$giveawayId}");
        self::assertStringStartsWith('🎁 Xmas keys is on! Prizes: ', Giveaways::announcement(Giveaways::find($this->user, $giveawayId)));
    }

}
