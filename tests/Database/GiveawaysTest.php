<?php
declare(strict_types=1);

/** Prizes sealed into a giveaway, claimed only by their winner, never twice. */
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

    public function testOnlyRealKeysMarkedForGiveawayBecomePrizes(): void
    {
        $g = $this->giveaway();
        $ok = $this->key('AAAA-1111');
        $available = $this->key('BBBB-2222', 'available');
        $placeholder = $this->key('CCCC-3333', 'for_giveaway', true);

        $result = Giveaways::addPrizes($this->user, $g, [$ok, $available, $placeholder]);

        self::assertSame(1, $result['added']);
        self::assertCount(2, $result['skipped']);
        self::assertStringNotContainsString('AAAA', (string) $this->pdo->query("SELECT sealed_code FROM giveaway_prizes WHERE game_key_id = {$ok}")->fetchColumn());
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

    public function testPrivateVaultKeysCanBeClaimedWithoutThePassword(): void
    {
        $k = $this->key('PRIV-0001');
        Vault::makePrivate($this->user, 'phpunit-password-1');
        $g = $this->giveaway();
        Giveaways::addPrizes($this->user, $g, [$k]);

        $this->newRequest();
        $_SESSION = [];
        $_COOKIE  = [];

        self::assertFalse(Vault::isUnlocked($this->user));
        self::assertSame('PRIV-0001', Giveaways::claim($this->winner($g, '1001'), $this->viewer('1001'), $this->prizeIds($g)[0]));
    }

    public function testEditingTheCodeRefreshesThePrize(): void
    {
        $g = $this->giveaway();
        $k = $this->key('OLD-CODE');
        Giveaways::addPrizes($this->user, $g, [$k]);

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
}
