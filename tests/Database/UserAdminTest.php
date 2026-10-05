<?php
declare(strict_types=1);

/** The last active administrator can never be demoted, deactivated or deleted. */
final class UserAdminTest extends DatabaseTestCase
{
    private static function isLastAdmin(array $user): bool
    {
        return (new ReflectionMethod(UserAdminController::class, 'isLastAdmin'))->invoke(null, $user);
    }

    private function user(int $id): array
    {
        return $this->pdo->query("SELECT * FROM users WHERE id = {$id}")->fetch();
    }

    public function testOnlyActiveAdminIsTheLastOne(): void
    {
        $this->pdo->exec("UPDATE users SET is_active = false WHERE role = 'admin'");
        $admin = $this->createUser('phpunit_admin');
        $this->pdo->exec("UPDATE users SET role = 'admin' WHERE id = {$admin}");

        self::assertTrue(self::isLastAdmin($this->user($admin)));

        $second = $this->createUser('phpunit_admin2');
        $this->pdo->exec("UPDATE users SET role = 'admin' WHERE id = {$second}");

        self::assertFalse(self::isLastAdmin($this->user($admin)));
    }

    public function testRegularAndInactiveUsersAreNeverTheLastAdmin(): void
    {
        $this->pdo->exec("UPDATE users SET is_active = false WHERE role = 'admin'");
        $user = $this->createUser('phpunit_plain');

        self::assertFalse(self::isLastAdmin($this->user($user)));

        $this->pdo->exec("UPDATE users SET role = 'admin', is_active = false WHERE id = {$user}");
        self::assertFalse(self::isLastAdmin($this->user($user)));
    }
}
