<?php
declare(strict_types=1);

/** Administrators taking keys back from giveaway winners: what happens to the key, the winner and the streamer. */
final class PrizeRemovalsTest extends DatabaseTestCase
{
    private int $user;
    private int $admin;
    private int $game;

    protected function setUp(): void
    {
        parent::setUp();

        Lang::setLocale('en');
        $this->user  = $this->createUser('phpunit_giver');
        $this->admin = $this->createUser('phpunit_admin');
        $stmt = $this->pdo->prepare('INSERT INTO games (title, slug) VALUES (?, ?) RETURNING id');
        $stmt->execute(['PHPUnit Quest', 'phpunit-quest-' . bin2hex(random_bytes(3))]);
        $this->game = (int) $stmt->fetchColumn();
    }

    private function key(string $code): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_keys (user_id, game_id, key_platform_id, game_platform_id, key_code, key_hash, status)
             VALUES (?, ?, (SELECT id FROM key_platforms ORDER BY id LIMIT 1), (SELECT id FROM game_platforms ORDER BY id LIMIT 1), ?, ?, 'for_giveaway')
             RETURNING id"
        );
        $stmt->execute([$this->user, $this->game, Vault::encryptCode($this->user, $code), Vault::hashCode($this->user, $code)]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{giveaway:int, key:int, prize:int} */
    private function giveawayWithKey(string $mode = 'pick'): array
    {
        $stmt = $this->pdo->prepare("INSERT INTO giveaways (user_id, title, keyword, winner_mode, status) VALUES (?, 'Test', ?, ?, 'open') RETURNING id");
        $stmt->execute([$this->user, 'k' . bin2hex(random_bytes(3)), $mode]);
        $giveaway = (int) $stmt->fetchColumn();
        $key = $this->key('AAAA-' . random_int(1000, 9999));
        Giveaways::addPrizes($this->user, $giveaway, [$key]);

        return ['giveaway' => $giveaway, 'key' => $key, 'prize' => (int) $this->pdo->query("SELECT id FROM giveaway_prizes WHERE game_key_id = {$key}")->fetchColumn()];
    }

    /** @return array{id:int, token:string} */
    private function winner(int $giveawayId, string $twitchId, ?int $prizeId = null): array
    {
        $token = (new ReflectionMethod(Giveaways::class, 'recordWinner'))
            ->invoke(null, Giveaways::find($this->user, $giveawayId), $twitchId, 'user' . $twitchId, null, 'manual', $prizeId);

        return ['id' => (int) $this->pdo->query("SELECT id FROM giveaway_winners WHERE twitch_user_id = '{$twitchId}' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'token' => $token];
    }

    private function viewer(string $twitchId): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO viewers (twitch_user_id, twitch_login) VALUES (?, ?) RETURNING *');
        $stmt->execute([$twitchId, 'user' . $twitchId]);

        return $stmt->fetch();
    }

    private function value(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }

    public function testClaimedKeyRevokedLeavesTheWinnersPrizes(): void
    {
        $g = $this->giveawayWithKey();
        $w = $this->winner($g['giveaway'], '2001');
        $viewer = $this->viewer('2001');
        Giveaways::claim($w['token'], $viewer, $g['prize']);
        self::assertCount(1, Giveaways::prizesOf($viewer));

        self::assertSame('revoked', PrizeRemovals::remove($this->admin, $w['id'], 'Used a second account', 'revoked'));

        self::assertSame([], Giveaways::prizesOf($viewer));
        self::assertSame('revoked', $this->value("SELECT status FROM game_keys WHERE id = {$g['key']}"));
        self::assertFalse($this->value("SELECT 1 FROM giveaway_prizes WHERE id = {$g['prize']}"));
        self::assertSame('removed', PrizeRemovals::stateOf($this->pdo->query("SELECT * FROM giveaway_winners WHERE id = {$w['id']}")->fetch()));
        self::assertSame('Used a second account', $this->value("SELECT reason FROM prize_removals WHERE winner_id = {$w['id']}"));
    }

    public function testClaimedKeyReturnedCanBeWonAgain(): void
    {
        $g = $this->giveawayWithKey();
        $w = $this->winner($g['giveaway'], '2002');
        Giveaways::claim($w['token'], $this->viewer('2002'), $g['prize']);

        PrizeRemovals::remove($this->admin, $w['id'], 'Wrong person', 'returned');

        self::assertSame('for_giveaway', $this->value("SELECT status FROM game_keys WHERE id = {$g['key']}"));
        self::assertNull($this->value("SELECT claimed_at FROM giveaway_prizes WHERE id = {$g['prize']}"));

        $again = $this->winner($g['giveaway'], '2003');
        self::assertNotNull(Giveaways::claim($again['token'], $this->viewer('2003'), $g['prize']));
    }

    public function testUnclaimedAssignedKeyGoesBackToTheGiveaway(): void
    {
        $g = $this->giveawayWithKey('assigned');
        $w = $this->winner($g['giveaway'], '2004', $g['prize']);
        self::assertSame((string) $w['id'], (string) $this->value("SELECT winner_id FROM giveaway_prizes WHERE id = {$g['prize']}"));

        self::assertSame('freed', PrizeRemovals::remove($this->admin, $w['id'], 'Bot account'));

        self::assertNull($this->value("SELECT winner_id FROM giveaway_prizes WHERE id = {$g['prize']}"));
        self::assertSame('for_giveaway', $this->value("SELECT status FROM game_keys WHERE id = {$g['key']}"));

        $this->expectException(UserError::class);
        Giveaways::claim($w['token'], $this->viewer('2004'), null);
    }

    public function testNeedsAReasonAndCannotRemoveTwice(): void
    {
        $g = $this->giveawayWithKey();
        $w = $this->winner($g['giveaway'], '2005');

        try {
            PrizeRemovals::remove($this->admin, $w['id'], '   ');
            self::fail('A removal without a reason went through.');
        } catch (UserError) {
        }

        PrizeRemovals::remove($this->admin, $w['id'], 'Duplicate entry');

        $this->expectException(UserError::class);
        PrizeRemovals::remove($this->admin, $w['id'], 'Again');
    }

    public function testStreamerIsNotifiedWithTheReason(): void
    {
        $g = $this->giveawayWithKey();
        $w = $this->winner($g['giveaway'], '2006');
        Giveaways::claim($w['token'], $this->viewer('2006'), $g['prize']);

        PrizeRemovals::remove($this->admin, $w['id'], 'Shared the *code* publicly', 'revoked');

        $body = (string) $this->value(
            "SELECT t.body FROM notifications n JOIN notification_recipients r ON r.notification_id = n.id
               JOIN notification_texts t ON t.notification_id = n.id AND t.locale = 'en'
              WHERE r.user_id = {$this->user} ORDER BY n.id DESC LIMIT 1"
        );
        self::assertStringContainsString('<blockquote>Shared the *code* publicly</blockquote>', NoteFormat::html($body), 'The reason is quoted and its marks stay as typed.');
        self::assertStringContainsString('Shared the *code* publicly', NoteFormat::plain($body));
    }

    public function testSearchFindsByLoginAndState(): void
    {
        $g = $this->giveawayWithKey();
        $w = $this->winner($g['giveaway'], '2007');
        Giveaways::claim($w['token'], $this->viewer('2007'), $g['prize']);

        $found = PrizeRemovals::search('user2007', 'claimed');
        self::assertSame([$w['id']], array_map(static fn (array $r): int => (int) $r['id'], $found));
        self::assertSame('PHPUnit Quest', $found[0]['game_title']);

        PrizeRemovals::remove($this->admin, $w['id'], 'Test');
        self::assertSame([], PrizeRemovals::search('user2007', 'claimed'));
        self::assertSame('PHPUnit Quest', PrizeRemovals::search('user2007', 'removed')[0]['removed_game']);
    }
}
