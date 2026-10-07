<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Runs each test inside a transaction that is always rolled back, so the
 * development database is never changed by the suite.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->beginTransaction();
        $_SESSION = [];
        $_COOKIE  = [];
        self::resetStatic(Vault::class, 'keys', []);
        self::resetStatic(Settings::class, 'cache', null);
        ErrorLog::useConnection($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        ErrorLog::useConnection(null);

        self::resetStatic(Vault::class, 'keys', []);
        self::resetStatic(Settings::class, 'cache', null);
    }

    /** A throwaway user that only exists inside this test's transaction. */
    protected function createUser(string $name = 'phpunit_user'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash) VALUES (?, ?, 'x') RETURNING id"
        );
        $stmt->execute([$name, $name . '@example.com']);

        return (int) $stmt->fetchColumn();
    }

    /** Forgets data keys unwrapped so far, as a new request would. */
    protected function newRequest(): void
    {
        self::resetStatic(Vault::class, 'keys', []);
    }

    protected static function resetStatic(string $class, string $property, mixed $value): void
    {
        (new ReflectionProperty($class, $property))->setValue(null, $value);
    }
}
