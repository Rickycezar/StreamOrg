<?php
declare(strict_types=1);

/** The chat overlay's badge images: Twitch's global and the channel's own, cached, given only to overlays that show badges. */
final class OverlayBadgesTest extends DatabaseTestCase
{
    private int $calls = 0;

    private ?array $answer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DELETE FROM twitch_badge_cache');

        OverlayBadges::$fetch = function (string $path, array $query): ?array {
            $this->calls++;

            if ($this->answer === null) {
                return null;
            }

            return $path === 'chat/badges/global'
                ? [['set_id' => 'vip', 'versions' => [['id' => '1', 'image_url_2x' => 'https://static-cdn.jtvnw.net/badges/v1/vip/2']]],
                   ['set_id' => 'subscriber', 'versions' => [['id' => '0', 'image_url_2x' => 'https://static-cdn.jtvnw.net/badges/v1/global-sub/2']]]]
                : [['set_id' => 'subscriber', 'versions' => [['id' => '0', 'image_url_2x' => 'https://static-cdn.jtvnw.net/badges/v1/own-sub/2'],
                                                             ['id' => '12', 'image_url_2x' => 'https://static-cdn.jtvnw.net/badges/v1/own-sub-12/2']]],
                   ['set_id' => 'bad', 'versions' => [['id' => '1', 'image_url_2x' => 'javascript:alert(1)']]]];
        };
    }

    protected function tearDown(): void
    {
        OverlayBadges::$fetch = null;
        parent::tearDown();
    }

    public function testTheChannelsBadgesReplaceTheGlobalOnesAndAreCached(): void
    {
        $this->answer = [];
        $map = OverlayBadges::map('777');

        self::assertSame('https://static-cdn.jtvnw.net/badges/v1/vip/2', $map['vip/1']);
        self::assertSame('https://static-cdn.jtvnw.net/badges/v1/own-sub/2', $map['subscriber/0'], 'The channel\'s own badge wins.');
        self::assertSame('https://static-cdn.jtvnw.net/badges/v1/own-sub-12/2', $map['subscriber/12']);
        self::assertArrayNotHasKey('bad/1', $map, 'Only https images.');
        self::assertSame(2, $this->calls);

        OverlayBadges::map('777');
        self::assertSame(2, $this->calls, 'Kept for a while.');

        $this->pdo->exec("UPDATE twitch_badge_cache SET fetched_at = now() - interval '1 day'");
        $this->answer = null;
        self::assertSame($map, OverlayBadges::map('777'), 'When Twitch does not answer, the last copy is used.');
    }

    public function testOnlyAnOverlayThatShowsBadgesGetsThem(): void
    {
        $this->answer = [];
        $user = $this->createUser('phpunit_badges_overlay');
        $key  = static fn (array $o): string => substr((string) $o['link'], strpos((string) $o['link'], '#') + 1);

        $chat  = Overlays::find(Overlays::create($user, 'chat'), $user);
        $alert = Overlays::find(Overlays::create($user, 'alert'), $user);

        self::assertArrayHasKey('vip/1', Overlays::badges($key($chat)) ?? []);
        self::assertNull(Overlays::badges($key($alert)));
        self::assertNull(Overlays::badges('not-a-key'));

        $this->pdo->exec('UPDATE overlays SET is_enabled = false WHERE id = ' . (int) $chat['id']);
        self::assertNull(Overlays::badges($key($chat)), 'Not for an overlay that is off.');
    }
}
