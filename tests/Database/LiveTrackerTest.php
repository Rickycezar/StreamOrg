<?php
declare(strict_types=1);

/** Planned content following the Twitch channel: online, category changes, offline. */
final class LiveTrackerTest extends DatabaseTestCase
{
    private int $user;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('phpunit_live');
        $this->pdo->prepare("UPDATE users SET timezone = 'America/Sao_Paulo' WHERE id = ?")->execute([$this->user]);
        $this->pdo->prepare(
            "INSERT INTO twitch_connections (user_id, twitch_user_id, twitch_login, access_token, refresh_token, expires_at, scopes)
             VALUES (?, 'tw-1', 'phpunit', 'x', 'x', now(), '')"
        )->execute([$this->user]);

        $this->today = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
    }

    private function game(string $title, ?string $categoryId = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO games (title, slug, twitch_category_id) VALUES (?, ?, ?) RETURNING id');
        $stmt->execute([$title, 'phpunit-' . bin2hex(random_bytes(4)), $categoryId]);

        return (int) $stmt->fetchColumn();
    }

    private function content(string $title, string $status, ?string $at, array $games = []): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO streams (user_id, streaming_platform_id, title, status, scheduled_start)
             VALUES (?, (SELECT id FROM streaming_platforms WHERE code = 'twitch'), ?, ?, ?) RETURNING id"
        );
        $stmt->execute([$this->user, $title, $status, $at === null ? null : $this->today->modify($at)->format(DATE_ATOM)]);
        $id = (int) $stmt->fetchColumn();

        foreach ($games as $order => $game) {
            $this->pdo->prepare('INSERT INTO stream_games (stream_id, game_id, play_order) VALUES (?, ?, ?)')
                ->execute([$id, $game, $order + 1]);
        }

        return $id;
    }

    private function stateOf(int $id): array
    {
        return $this->pdo->query("SELECT status, scheduled_start IS NOT NULL AS dated, actual_start IS NOT NULL AS started,
                                         ended_at IS NOT NULL AS ended FROM streams WHERE id = {$id}")->fetch();
    }

    private function at(string $time): DateTimeImmutable
    {
        return $this->today->modify($time);
    }

    public function testCategoryChangeFinishesLiveAndStartsTheMatchingPlan(): void
    {
        $hades  = $this->game('Hades', '111');
        $knight = $this->game('Hollow Knight: Silksong');
        $first  = $this->content('Hades run', 'planned', '+20 hours', [$hades]);
        $second = $this->content('Silksong', 'planned', '+22 hours', [$knight]);

        LiveTracker::online($this->user, $this->at('+19 hours 55 minutes'), '111', 'Hades');
        self::assertSame('live', $this->stateOf($first)['status']);

        LiveTracker::categoryChanged($this->user, '222', 'Hollow Knight Silksong', $this->at('+21 hours 50 minutes'));

        self::assertSame(['status' => 'done', 'dated' => true, 'started' => true, 'ended' => true], $this->stateOf($first));
        self::assertSame('live', $this->stateOf($second)['status']);
        self::assertSame('222', $this->pdo->query("SELECT category_id FROM user_twitch_categories WHERE game_id = {$knight}")->fetchColumn());
    }

    public function testNoMatchMeansChattingUntilTheNextChange(): void
    {
        $hades  = $this->game('Hades', '111');
        $knight = $this->game('Hollow Knight', '333');
        $first  = $this->content('Hades run', 'live', '+20 hours', [$hades]);
        $second = $this->content('Knight', 'planned', '+22 hours', [$knight]);
        $this->pdo->prepare("UPDATE twitch_connections SET is_live = true, live_since = ?, category_id = '111' WHERE user_id = ?")
            ->execute([$this->at('+20 hours')->format(DATE_ATOM), $this->user]);

        LiveTracker::categoryChanged($this->user, LiveTracker::JUST_CHATTING, 'Just Chatting', $this->at('+21 hours'));

        self::assertSame('done', $this->stateOf($first)['status']);
        self::assertSame('planned', $this->stateOf($second)['status']);
        self::assertSame('chatting', $this->pdo->query("SELECT kind FROM twitch_live_log WHERE user_id = {$this->user} ORDER BY id DESC LIMIT 1")->fetchColumn());

        LiveTracker::categoryChanged($this->user, '333', 'Hollow Knight', $this->at('+21 hours 30 minutes'));
        self::assertSame('live', $this->stateOf($second)['status']);
    }

    public function testMultiGameContentStaysLiveAcrossItsGames(): void
    {
        $a = $this->game('Game A', '1');
        $b = $this->game('Game B', '2');
        $id = $this->content('Double feature', 'live', '+20 hours', [$a, $b]);
        $this->pdo->prepare("UPDATE twitch_connections SET is_live = true, live_since = now(), category_id = '1' WHERE user_id = ?")
            ->execute([$this->user]);

        LiveTracker::categoryChanged($this->user, '2', 'Game B', $this->at('+21 hours'));

        self::assertSame('live', $this->stateOf($id)['status']);
    }

    public function testTitleOnlyUpdateChangesNothing(): void
    {
        $a  = $this->game('Game A', '1');
        $id = $this->content('A', 'live', '+20 hours', [$a]);
        $this->pdo->prepare("UPDATE twitch_connections SET is_live = true, live_since = now(), category_id = '9' WHERE user_id = ?")
            ->execute([$this->user]);

        LiveTracker::categoryChanged($this->user, '9', 'Unrelated', $this->at('+21 hours'));

        self::assertSame('live', $this->stateOf($id)['status']);
    }

    public function testChangesWhileOfflineOnlyRememberTheCategory(): void
    {
        $a  = $this->game('Game A', '1');
        $id = $this->content('A', 'planned', '+20 hours', [$a]);

        LiveTracker::categoryChanged($this->user, '1', 'Game A', $this->at('+19 hours'));

        self::assertSame('planned', $this->stateOf($id)['status']);
        self::assertSame('1', $this->pdo->query("SELECT category_id FROM twitch_connections WHERE user_id = {$this->user}")->fetchColumn());
    }

    public function testChattingContentMatchesJustChatting(): void
    {
        $id = $this->content('Chat with viewers', 'planned', '+20 hours');

        LiveTracker::online($this->user, $this->at('+20 hours'), LiveTracker::JUST_CHATTING, 'Just Chatting');

        self::assertSame('live', $this->stateOf($id)['status']);
    }

    public function testOfflineFinishesLiveAndReturnsMissedPlansToTheBacklog(): void
    {
        $a = $this->game('Game A', '1');
        $live      = $this->content('Live one', 'live', '+20 hours', [$a]);
        $missed    = $this->content('Missed', 'planned', '+21 hours');
        $later     = $this->content('Second stream', 'planned', '+23 hours 30 minutes');
        $yesterday = $this->content('Old plan', 'planned', '-4 hours');
        $this->pdo->prepare('UPDATE twitch_connections SET is_live = true, live_since = ? WHERE user_id = ?')
            ->execute([$this->at('+20 hours')->format(DATE_ATOM), $this->user]);

        LiveTracker::offline($this->user, $this->at('+22 hours'));

        self::assertSame('done', $this->stateOf($live)['status']);
        self::assertSame(['status' => 'planned', 'dated' => false, 'started' => false, 'ended' => false], $this->stateOf($missed));
        self::assertTrue($this->stateOf($later)['dated']);
        self::assertTrue($this->stateOf($yesterday)['dated']);
        self::assertFalse((bool) $this->pdo->query("SELECT is_live FROM twitch_connections WHERE user_id = {$this->user}")->fetchColumn());
    }

    public function testStreamPastMidnightStillFindsTheEveningPlans(): void
    {
        $missed = $this->content('Late plan', 'planned', '+23 hours');
        $this->pdo->prepare('UPDATE twitch_connections SET is_live = true, live_since = ? WHERE user_id = ?')
            ->execute([$this->at('+22 hours')->format(DATE_ATOM), $this->user]);

        LiveTracker::offline($this->user, $this->at('+25 hours'));

        self::assertFalse($this->stateOf($missed)['dated']);
    }
}
