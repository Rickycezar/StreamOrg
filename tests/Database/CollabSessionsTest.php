<?php
declare(strict_types=1);

/** Planning a collab together: invitations, shared time, proposals and their notifications. */
final class CollabSessionsTest extends DatabaseTestCase
{
    private int $host;
    private int $guest;
    private int $collab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host  = $this->createUser('phpunit_host_' . bin2hex(random_bytes(3)));
        $this->guest = $this->createUser('phpunit_guest_' . bin2hex(random_bytes(3)));

        foreach ([[$this->host, 'tw-host'], [$this->guest, 'tw-guest']] as [$user, $twitch]) {
            $this->pdo->prepare(
                "INSERT INTO twitch_connections (user_id, twitch_user_id, twitch_login, access_token, refresh_token, expires_at, scopes)
                 VALUES (?, ?, ?, 'x', 'x', now(), '')"
            )->execute([$user, $twitch, $twitch === 'tw-host' ? 'hostlogin' : 'guestlogin']);
        }

        $streamer = (int) $this->pdo->query(
            "INSERT INTO streamers (user_id, name, source_provider, source_ref) VALUES ({$this->host}, 'Guest', 'twitch', 'tw-guest') RETURNING id"
        )->fetchColumn();
        $this->collab = (int) $this->pdo->query(
            "INSERT INTO collabs (user_id, title, status, proposed_at) VALUES ({$this->host}, 'Co-op night', 'agreed', '2030-05-10 20:00+00') RETURNING id"
        )->fetchColumn();
        $this->pdo->exec("INSERT INTO collab_streamers (collab_id, streamer_id, role, confirmation) VALUES ({$this->collab}, {$streamer}, 'guest', 'confirmed')");
    }

    private function streamOf(int $user, int $session): array
    {
        $stmt = $this->pdo->prepare('SELECT id, title, scheduled_start, planned_minutes FROM streams WHERE user_id = ? AND collab_session_id = ?');
        $stmt->execute([$user, $session]);

        return $stmt->fetch() ?: [];
    }

    private function lastNotificationFor(int $user): string
    {
        Lang::setLocale('en');

        return (string) (Notifications::forUser($user, 1)[0]['title'] ?? '');
    }

    public function testTheGuestIsFoundAndInvited(): void
    {
        self::assertSame(['guestlogin'], array_column(CollabSessions::linkedUsers($this->host, $this->pdo->query("SELECT id FROM streamers WHERE user_id = {$this->host}")->fetchAll(PDO::FETCH_COLUMN)), 'login'));

        $session = CollabSessions::invite($this->host, $this->collab);

        self::assertSame($session, CollabSessions::invite($this->host, $this->collab), 'one joint plan per collab');
        self::assertStringContainsString('invites you to plan a collab', $this->lastNotificationFor($this->guest));
        self::assertSame(1, CollabSessions::waitingFor($this->guest));

        $own = $this->streamOf($this->host, $session);
        self::assertSame('2030-05-10 17:00:00-03', $own['scheduled_start'] ?? null);
    }

    public function testJoiningGivesTheGuestTheirOwnContentAtTheSharedTime(): void
    {
        $session = CollabSessions::invite($this->host, $this->collab);
        CollabSessions::respond($this->guest, $session, true);

        $mine = $this->streamOf($this->guest, $session);
        self::assertSame($this->streamOf($this->host, $session)['scheduled_start'], $mine['scheduled_start']);
        self::assertStringContainsString('ft. @hostlogin', $mine['title']);
        self::assertStringContainsString('joined', $this->lastNotificationFor($this->host));
    }

    public function testATimeChangeIsProposedAndAppliesWhenEveryoneAgrees(): void
    {
        $session = CollabSessions::invite($this->host, $this->collab);
        CollabSessions::respond($this->guest, $session, true);
        $hostStream = (int) $this->streamOf($this->host, $session)['id'];

        $message = CollabSessions::guardTimeChange($this->host, $hostStream, new DateTimeImmutable('2030-05-12 21:00+00'), 90);

        self::assertNotNull($message, 'moving a shared plan proposes instead');
        self::assertSame('2030-05-10 17:00:00-03', $this->streamOf($this->host, $session)['scheduled_start']);
        self::assertStringContainsString('proposes a new time', $this->lastNotificationFor($this->guest));

        CollabSessions::answerProposal($this->guest, $session, true);

        foreach ([$this->host, $this->guest] as $user) {
            self::assertSame(['2030-05-12 18:00:00-03', 90], [$this->streamOf($user, $session)['scheduled_start'], (int) $this->streamOf($user, $session)['planned_minutes']]);
        }
        self::assertStringContainsString('New time agreed', $this->lastNotificationFor($this->host));
    }

    public function testATurnedDownProposalLeavesTheTimeAsItWas(): void
    {
        $session = CollabSessions::invite($this->host, $this->collab);
        CollabSessions::respond($this->guest, $session, true);

        self::assertFalse(CollabSessions::propose($this->guest, $session, new DateTimeImmutable('2030-06-01 20:00+00'), 120));
        CollabSessions::answerProposal($this->host, $session, false);

        self::assertSame('2030-05-10 17:00:00-03', $this->streamOf($this->guest, $session)['scheduled_start']);
        self::assertStringContainsString('turned down', $this->lastNotificationFor($this->guest));
    }

    public function testLeavingAndCancelling(): void
    {
        $session = CollabSessions::invite($this->host, $this->collab);
        CollabSessions::respond($this->guest, $session, true);
        $guestStream = (int) $this->streamOf($this->guest, $session)['id'];

        CollabSessions::leave($this->guest, $session);
        self::assertSame([], $this->streamOf($this->guest, $session));
        self::assertNotFalse($this->pdo->query("SELECT 1 FROM streams WHERE id = {$guestStream}")->fetchColumn(), 'their content stays theirs');
        self::assertStringContainsString('left', $this->lastNotificationFor($this->host));

        CollabSessions::leave($this->host, $session);
        self::assertSame('cancelled', $this->pdo->query("SELECT status FROM collab_sessions WHERE id = {$session}")->fetchColumn());
    }

    public function testDecliningAndStrangers(): void
    {
        $session = CollabSessions::invite($this->host, $this->collab);
        CollabSessions::respond($this->guest, $session, false);

        self::assertSame([], $this->streamOf($this->guest, $session));
        self::assertStringContainsString('declined', $this->lastNotificationFor($this->host));

        $stranger = $this->createUser('phpunit_stranger_' . bin2hex(random_bytes(3)));
        $this->expectException(UserError::class);
        CollabSessions::show($stranger, $session);
    }
}
