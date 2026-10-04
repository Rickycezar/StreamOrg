<?php
declare(strict_types=1);

final class UserSessionsDbTest extends DatabaseTestCase
{
    public function testSessionIsValidUntilRevoked(): void
    {
        $user = $this->createUser();
        UserSessions::begin($user);

        $id = UserSessions::currentId($user);
        self::assertNotNull($id);

        self::assertTrue(UserSessions::revoke($user, $id));
        self::assertNull(UserSessions::current($user));
    }

    public function testCannotRevokeSomeoneElsesSession(): void
    {
        $alice = $this->createUser('phpunit_alice');
        $bob   = $this->createUser('phpunit_bob');
        UserSessions::begin($alice);

        self::assertFalse(UserSessions::revoke($bob, (int) UserSessions::currentId($alice)));
        self::assertNotNull(UserSessions::current($alice));
    }

    public function testIdleSessionExpires(): void
    {
        $user = $this->createUser();
        UserSessions::begin($user);
        $id = UserSessions::currentId($user);

        $this->pdo->prepare("UPDATE user_sessions SET last_seen_at = now() - make_interval(mins => ?) WHERE id = ?")
            ->execute([UserSessions::idleMinutes() + 1, $id]);

        self::assertNull(UserSessions::current($user));
        self::assertSame('expired', $this->pdo->query("SELECT revoked_reason FROM user_sessions WHERE id = {$id}")->fetchColumn());
    }

    public function testRevokeAllKeepsTheCurrentOne(): void
    {
        $user = $this->createUser();

        UserSessions::begin($user);
        UserSessions::begin($user);
        UserSessions::begin($user);
        $current = UserSessions::currentId($user);

        self::assertSame(2, UserSessions::revokeAll($user, 'password_changed', $current));
        self::assertCount(1, UserSessions::active($user));
        self::assertSame(1, UserSessions::revokeAll($user, 'password_reset'));
    }

    public function testIdleLimitComesFromSettingsAndIsBounded(): void
    {
        Settings::set('session_idle_minutes', '720', null);
        self::assertSame(720, UserSessions::idleMinutes());

        Settings::set('session_idle_minutes', '1', null);
        self::assertSame(UserSessions::IDLE_MIN, UserSessions::idleMinutes());

        Settings::set('session_idle_minutes', '999999999', null);
        self::assertSame(UserSessions::IDLE_MAX, UserSessions::idleMinutes());
    }
}
