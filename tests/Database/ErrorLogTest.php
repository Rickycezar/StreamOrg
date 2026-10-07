<?php
declare(strict_types=1);

/** The error log: problems grouped by kind and counted, opened again when they come back, administrators told once. */
final class ErrorLogTest extends DatabaseTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Lang::setLocale('en');
        $this->pdo->exec("UPDATE users SET role = 'user' WHERE role = 'admin'");
        $this->admin = $this->createUser('phpunit_admin');
        $this->pdo->exec("UPDATE users SET role = 'admin' WHERE id = {$this->admin}");
    }

    private function notices(): int
    {
        return (int) $this->pdo->query("SELECT count(*) FROM notification_recipients WHERE user_id = {$this->admin}")->fetchColumn();
    }

    public function testTheSameProblemIsCountedNotRepeated(): void
    {
        $first  = ErrorLog::record('error', 'Game #12 not found', 'src/A.php', 10);
        $second = ErrorLog::record('error', 'Game #345 not found', 'src/A.php', 10);
        $other  = ErrorLog::record('error', 'Game #12 not found', 'src/A.php', 11);

        self::assertSame($first, $second);
        self::assertNotSame($first, $other);
        self::assertSame(2, (int) $this->pdo->query("SELECT count FROM app_errors WHERE id = {$first}")->fetchColumn());
        self::assertSame('Game #345 not found', $this->pdo->query("SELECT message FROM app_errors WHERE id = {$first}")->fetchColumn(), 'The latest example is kept.');
    }

    public function testAdministratorsAreToldOnceAndAgainWhenItComesBack(): void
    {
        $id = ErrorLog::record('error', 'Database went away', 'src/B.php', 5);
        ErrorLog::record('error', 'Database went away', 'src/B.php', 5);
        self::assertSame(1, $this->notices());

        ErrorLog::resolve([$id], $this->admin);
        self::assertNotContains($id, array_map('intval', array_column(ErrorLog::list('open'), 'id')));

        $this->pdo->exec("UPDATE app_errors SET resolved_at = now() - interval '1 minute' WHERE id = {$id}");
        ErrorLog::record('error', 'Database went away', 'src/B.php', 5);

        self::assertContains($id, array_map('intval', array_column(ErrorLog::list('open'), 'id')));
        self::assertSame(2, $this->notices());
    }

    public function testNoticesAndImportsAreNotNotified(): void
    {
        ErrorLog::record('notice', 'Twitch said no', 'src/C.php', 1);
        ErrorLog::record('error', 'Old failure', null, null, null, ['source' => 'import', 'at' => '2026-01-02T03:04:05Z', 'notify' => false]);

        self::assertSame(0, $this->notices());
        self::assertSame('2026-01-02', substr((string) $this->pdo->query("SELECT first_seen AT TIME ZONE 'UTC' FROM app_errors WHERE message = 'Old failure'")->fetchColumn(), 0, 10));
    }

    public function testAnOlderImportedLineDoesNotHideTheLatestDetails(): void
    {
        $id = ErrorLog::record('error', 'Broken page', 'src/D.php', 3, null, ['path' => '/new', 'notify' => false]);
        ErrorLog::record('error', 'Broken page', 'src/D.php', 3, null, ['path' => '/old', 'at' => '2020-01-01T00:00:00Z', 'notify' => false]);

        $row = $this->pdo->query("SELECT path, first_seen < now() - interval '1 year' AS older, count FROM app_errors WHERE id = {$id}")->fetch();
        self::assertSame('/new', $row['path']);
        self::assertTrue($row['older']);
        self::assertSame(2, (int) $row['count']);
    }

    public function testExceptionsKeepATraceWithoutArguments(): void
    {
        $secret = 'hunter2-password';

        try {
            (static function (string $password): never {
                throw new RuntimeException('Something broke');
            })($secret);
        } catch (RuntimeException $e) {
            ErrorLog::exception($e);
        }

        $row = $this->pdo->query("SELECT * FROM app_errors WHERE message = 'RuntimeException: Something broke'")->fetch();
        self::assertNotFalse($row);
        self::assertSame('tests/Database/ErrorLogTest.php', $row['file']);
        self::assertStringNotContainsString($secret, (string) $row['trace']);
    }

    public function testLoggingNeverThrows(): void
    {
        ErrorLog::useConnection(new class extends PDO {
            public function __construct()
            {
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                throw new PDOException('down');
            }
        });

        self::assertNull(ErrorLog::record('error', 'Nowhere to write'));
    }
}
