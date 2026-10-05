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

    public function testAUserWithGiveawayPrizesCanBeDeleted(): void
    {
        $user = $this->createUser('phpunit_giver');
        $game = (int) $this->pdo->query("INSERT INTO games (title, slug) VALUES ('X', 'phpunit-x-" . bin2hex(random_bytes(3)) . "') RETURNING id")->fetchColumn();
        $key  = (int) $this->pdo->query(
            "INSERT INTO game_keys (user_id, game_id, key_platform_id, game_platform_id, key_code, key_hash, status)
             VALUES ({$user}, {$game}, (SELECT id FROM key_platforms LIMIT 1), (SELECT id FROM game_platforms LIMIT 1), 'c', 'h', 'for_giveaway') RETURNING id"
        )->fetchColumn();
        $g = (int) $this->pdo->query("INSERT INTO giveaways (user_id, title) VALUES ({$user}, 'G') RETURNING id")->fetchColumn();
        $this->pdo->exec("INSERT INTO giveaway_prizes (giveaway_id, game_key_id) VALUES ({$g}, {$key})");

        $source = (string) file_get_contents((new ReflectionClass(UserAdminController::class))->getFileName());

        self::assertStringContainsString('DELETE FROM giveaway_prizes', $source);

        Database::transaction(static function (PDO $pdo) use ($user): void {
            $pdo->prepare('DELETE FROM giveaway_prizes p USING giveaways g WHERE g.id = p.giveaway_id AND g.user_id = ?')->execute([$user]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user]);
        });

        self::assertFalse($this->pdo->query("SELECT 1 FROM users WHERE id = {$user}")->fetchColumn());
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
