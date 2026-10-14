<?php
declare(strict_types=1);

/** Channel events by filter and page, and the administrators' switches for unfollow tracking. */
final class ChannelEventsTest extends DatabaseTestCase
{
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();

        Lang::setLocale('en');
        $this->user = $this->createUser('phpunit_events');
    }

    private function event(string $type, array $fields = []): void
    {
        $this->pdo->prepare(
            'INSERT INTO channel_events (user_id, type, actor_login, actor_name, amount, tier, data) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->user, $type, 'fan', 'Fan', $fields['amount'] ?? null, $fields['tier'] ?? null, json_encode($fields['data'] ?? [])]);
    }

    public function testEventsAreListedByFilterWithoutUnfollows(): void
    {
        $this->event('follow');
        $this->event('resub', ['amount' => 14, 'tier' => '2', 'data' => ['streak' => 3]]);
        $this->event('cheer', ['amount' => 500]);
        $this->event('unfollow');

        self::assertSame(3, ChannelEvents::page($this->user, null)['total']);
        self::assertSame(['resub'], array_column(ChannelEvents::page($this->user, 'subs')['rows'], 'type'));
        self::assertSame(['unfollow'], array_column(ChannelEvents::page($this->user, 'unfollows')['rows'], 'type'));
        self::assertSame(1, ChannelEvents::counts($this->user)['bits']);

        $resub = ChannelEvents::page($this->user, 'subs')['rows'][0];
        self::assertSame('Tier 2 · 14 months · 3 in a row', ChannelEvents::details($resub));
    }

    public function testUnfollowsNeedTheSettingAndTheChannelSwitchedOn(): void
    {
        ChannelEvents::setUnfollowsFor($this->user, true);
        Settings::set('unfollows.enabled', '0', null);
        self::assertFalse(ChannelEvents::unfollowsFor($this->user));

        Settings::set('unfollows.enabled', '1', null);
        self::assertTrue(ChannelEvents::unfollowsFor($this->user));

        $this->pdo->exec("UPDATE follower_tracking SET follower_count = 10, checked_at = now() WHERE user_id = {$this->user}");
        $this->pdo->exec("INSERT INTO channel_followers (user_id, follower_id, login) VALUES ({$this->user}, '1', 'a')");

        ChannelEvents::setUnfollowsFor($this->user, false);
        self::assertFalse(ChannelEvents::unfollowsFor($this->user));

        ChannelEvents::setUnfollowsFor($this->user, true);
        self::assertNull(ChannelEvents::tracking($this->user)['follower_count'], 'Switched on again, it starts over.');
        self::assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM channel_followers WHERE user_id = {$this->user}")->fetchColumn());
    }
}
