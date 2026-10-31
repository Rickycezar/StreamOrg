<?php
declare(strict_types=1);

/** Badge-gated giveaways: the channel's campaign badges, chosen with their name and icon, sent to the battle draw. */
final class GiveawayBadgesTest extends DatabaseTestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

    private int $user;
    private int $fetched = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('phpunit_badges');
        $this->pdo->prepare(
            "INSERT INTO twitch_connections (user_id, twitch_user_id, twitch_login, access_token, refresh_token, expires_at, scopes)
             VALUES (?, '777', 'phpunit_badges', 'x', 'x', now(), '')"
        )->execute([$this->user]);

        GiveawayBadges::$fetch = function (string $channel): array {
            $this->fetched++;
            self::assertSame('777', $channel);

            return [
                ['set_id' => 'subscriber', 'versions' => [['id' => '0', 'title' => 'Subscriber', 'image_url_2x' => 'https://static-cdn.jtvnw.net/badges/v1/s/2']]],
                ['set_id' => 'campaign-777-b', 'versions' => [['id' => '1', 'title' => 'Sorteio de Outubro', 'image_url_2x' => 'https://static-cdn.jtvnw.net/badges/v1/b/2']]],
                ['set_id' => 'campaign-777-a', 'versions' => [['id' => '1', 'title' => 'Anniversary', 'image_url_2x' => 'https://evil.example/a.png']]],
            ];
        };
        GiveawayBadges::$download = static fn (string $url): string => self::PNG;
    }

    protected function tearDown(): void
    {
        GiveawayBadges::$fetch = null;
        GiveawayBadges::$download = null;
        parent::tearDown();
    }

    public function testOnlyTheChannelsCampaignBadgesAreOffered(): void
    {
        self::assertSame(['campaign-777-a', 'campaign-777-b'], array_column(GiveawayBadges::campaign($this->user), 'id'), 'Campaign badges only, by name.');
        self::assertSame(['Anniversary', 'Sorteio de Outubro'], array_column(GiveawayBadges::campaign($this->user), 'title'));

        $this->expectException(UserError::class);
        GiveawayBadges::campaign($this->createUser('phpunit_no_twitch'));
    }

    public function testChosenBadgesKeepTheirNameAndATwitchIconCopiedIn(): void
    {
        $chosen = GiveawayBadges::choose($this->user, ['campaign-777-b', 'campaign-777-a', 'campaign-777-b']);

        self::assertSame(['campaign-777-b', 'campaign-777-a'], array_column($chosen, 'id'));
        self::assertSame('Sorteio de Outubro', $chosen[0]['title']);
        self::assertSame('data:image/png;base64,' . base64_encode(self::PNG), $chosen[0]['image']);
        self::assertSame('', $chosen[1]['image'], 'Icons are copied only from Twitch.');

        $before = $this->fetched;
        self::assertSame($chosen, GiveawayBadges::choose($this->user, ['campaign-777-b', 'campaign-777-a'], $chosen));
        self::assertSame($before, $this->fetched, 'Badges already kept are not fetched again.');

        $this->expectException(UserError::class);
        GiveawayBadges::choose($this->user, ['subscriber']);
    }

    public function testTheBattleDrawGetsTheBadgesOnlyWhenTheGiveawayIsGated(): void
    {
        $badges = GiveawayBadges::choose($this->user, ['campaign-777-b']);
        $stmt   = $this->pdo->prepare(
            "INSERT INTO giveaways (user_id, title, keyword, winner_mode, claim_days, status, badge_gate, badges)
             VALUES (?, 'Badges', ?, 'pick', 30, 'open', true, CAST(? AS jsonb)) RETURNING id"
        );
        $stmt->execute([$this->user, 'k' . bin2hex(random_bytes(3)), json_encode($badges)]);
        $id = (int) $stmt->fetchColumn();

        if (Games::manifest('boss-draw') !== null) {
            $config = GameDraws::config($this->user, Giveaways::find($this->user, $id), 'boss-draw');
            self::assertSame([['id' => 'campaign-777-b', 'title' => 'Sorteio de Outubro', 'image' => $badges[0]['image']]], $config['badges']);
        }

        $this->pdo->exec("UPDATE giveaways SET badge_gate = false WHERE id = {$id}");
        self::assertSame([], GiveawayBadges::forGame(Giveaways::find($this->user, $id)), 'Switched off: no badges, though they are kept.');
        self::assertCount(1, json_decode((string) Giveaways::find($this->user, $id)['badges'], true));
    }
}
