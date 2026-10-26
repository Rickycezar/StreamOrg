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

    /** The host starts the plan and sends its invitations at the collab's time. */
    private function planned(string $message = ''): int
    {
        $session = CollabSessions::invite($this->host, $this->collab);
        CollabSessions::sendInvites($this->host, $session, new DateTimeImmutable('2030-05-10 20:00+00'), 120, $message);

        return $session;
    }

    /** The user opens the plan page, which reads its notifications. */
    private function opened(int $user, int $session): void
    {
        Notifications::markReadFor($user, CollabSessions::LINK . $session);
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
        self::assertSame(0, Notifications::unreadCount($this->guest), 'a draft tells nobody');
        self::assertSame([], array_column(CollabSessions::forUser($this->guest), 'id'), 'and only the host sees it');
        self::assertSame([$session], array_map('intval', array_column(CollabSessions::forUser($this->host), 'id')));

        try {
            CollabSessions::sendInvites($this->host, $session, null, 120, '');
            self::fail('Sent without a time');
        } catch (UserError) {
            self::assertSame(0, CollabSessions::waitingFor($this->guest));
        }

        CollabSessions::sendInvites($this->host, $session, new DateTimeImmutable('2030-05-11 22:00+00'), 90, 'Shall we?');

        self::assertStringContainsString('invites you to plan a collab', $this->lastNotificationFor($this->guest));
        self::assertSame(1, CollabSessions::waitingFor($this->guest));
        self::assertSame('2030-05-11 19:00:00-03', $this->streamOf($this->host, $session)['scheduled_start'] ?? null);
        self::assertSame(['Shall we?'], array_column(CollabSessions::messages($this->guest, $session), 'body'));
    }

    public function testADraftCanBeDiscardedQuietly(): void
    {
        $session = CollabSessions::invite($this->host, $this->collab);
        CollabSessions::leave($this->host, $session);

        self::assertFalse($this->pdo->query("SELECT 1 FROM collab_sessions WHERE id = {$session}")->fetchColumn());
        self::assertSame(0, Notifications::unreadCount($this->guest));
    }

    public function testJoiningGivesTheGuestTheirOwnContentAtTheSharedTime(): void
    {
        $session = $this->planned();
        CollabSessions::respond($this->guest, $session, true);

        $mine = $this->streamOf($this->guest, $session);
        self::assertSame($this->streamOf($this->host, $session)['scheduled_start'], $mine['scheduled_start']);
        self::assertStringContainsString('ft. @hostlogin', $mine['title']);
        self::assertStringContainsString('joined', $this->lastNotificationFor($this->host));
    }

    public function testATimeChangeIsProposedAndAppliesWhenEveryoneAgrees(): void
    {
        $session = $this->planned();
        CollabSessions::respond($this->guest, $session, true);
        $hostStream = (int) $this->streamOf($this->host, $session)['id'];
        $this->opened($this->guest, $session);
        $this->opened($this->host, $session);

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

    public function testSavingThePlanAtItsOwnTimeAsksNobody(): void
    {
        $session = $this->planned();
        CollabSessions::respond($this->guest, $session, true);
        $hostStream = (int) $this->streamOf($this->host, $session)['id'];
        $agreed     = $this->pdo->query("SELECT agreed_start, agreed_minutes FROM collab_sessions WHERE id = {$session}")->fetch();

        self::assertNull(CollabSessions::guardTimeChange($this->host, $hostStream, new DateTimeImmutable((string) $agreed['agreed_start']), (int) $agreed['agreed_minutes']));
        self::assertNull(CollabSessions::guardTimeChange($this->host, $hostStream, new DateTimeImmutable((string) $agreed['agreed_start']), null));
        self::assertFalse((bool) $this->pdo->query("SELECT proposed_at IS NOT NULL FROM collab_sessions WHERE id = {$session}")->fetchColumn(), 'nothing to answer');
    }

    public function testAPlanKeepsOneUnreadNotificationPerPersonUntilOpened(): void
    {
        $session = $this->planned();
        CollabSessions::respond($this->guest, $session, true);
        $hostStream = (int) $this->streamOf($this->host, $session)['id'];

        CollabSessions::guardTimeChange($this->host, $hostStream, new DateTimeImmutable('2030-05-12 21:00+00'), 90);
        CollabSessions::postMessage($this->host, $session, 'See you there!');
        CollabSessions::postMessage($this->host, $session, 'Bring snacks');

        self::assertSame(1, Notifications::unreadCount($this->guest), 'one unread for the whole plan');
        self::assertStringContainsString('News in', $this->lastNotificationFor($this->guest));

        CollabSessions::messages($this->guest, $session);
        self::assertSame(0, Notifications::unreadCount($this->guest), 'opening the plan reads it');

        CollabSessions::postMessage($this->host, $session, 'One more thing');
        self::assertSame(1, Notifications::unreadCount($this->guest));
        self::assertStringContainsString('wrote in', $this->lastNotificationFor($this->guest));
        self::assertSame(['See you there!', 'Bring snacks', 'One more thing'], array_column(CollabSessions::messages($this->guest, $session), 'body'));
    }

    public function testOnlyPeopleTakingPartWrite(): void
    {
        $session = $this->planned();

        try {
            CollabSessions::postMessage($this->guest, $session, 'hello');
            self::fail('An invited guest wrote before joining');
        } catch (UserError) {
            self::assertSame([], CollabSessions::messages($this->guest, $session));
        }

        $this->expectException(UserError::class);
        CollabSessions::postMessage($this->host, $session, '   ');
    }

    public function testATurnedDownProposalLeavesTheTimeAsItWas(): void
    {
        $session = $this->planned();
        CollabSessions::respond($this->guest, $session, true);

        self::assertFalse(CollabSessions::propose($this->guest, $session, new DateTimeImmutable('2030-06-01 20:00+00'), 120));
        $this->opened($this->guest, $session);
        CollabSessions::answerProposal($this->host, $session, false);

        self::assertSame('2030-05-10 17:00:00-03', $this->streamOf($this->guest, $session)['scheduled_start']);
        self::assertStringContainsString('turned down', $this->lastNotificationFor($this->guest));
    }

    public function testLeavingAndCancelling(): void
    {
        $session = $this->planned();
        CollabSessions::respond($this->guest, $session, true);
        $guestStream = (int) $this->streamOf($this->guest, $session)['id'];
        $this->opened($this->host, $session);

        CollabSessions::leave($this->guest, $session);
        self::assertSame([], $this->streamOf($this->guest, $session));
        self::assertNotFalse($this->pdo->query("SELECT 1 FROM streams WHERE id = {$guestStream}")->fetchColumn(), 'their content stays theirs');
        self::assertStringContainsString('left', $this->lastNotificationFor($this->host));

        CollabSessions::leave($this->host, $session);
        self::assertSame('cancelled', $this->pdo->query("SELECT status FROM collab_sessions WHERE id = {$session}")->fetchColumn());
    }

    public function testDecliningAndStrangers(): void
    {
        $session = $this->planned();
        CollabSessions::respond($this->guest, $session, false);

        self::assertSame([], $this->streamOf($this->guest, $session));
        self::assertStringContainsString('declined', $this->lastNotificationFor($this->host));

        $stranger = $this->createUser('phpunit_stranger_' . bin2hex(random_bytes(3)));
        $this->expectException(UserError::class);
        CollabSessions::show($stranger, $session);
    }
}
