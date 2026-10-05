<?php
declare(strict_types=1);

/**
 * Application-wide PDO connection to PostgreSQL.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = Config::get('db', []);

        self::$pdo = self::connect(
            host:     (string) ($db['host'] ?? '127.0.0.1'),
            port:     (int)    ($db['port'] ?? 5432),
            dbname:   (string) ($db['name'] ?? ''),
            user:     (string) ($db['user'] ?? ''),
            password: $db['password'] ?? null,
            sslmode:  (string) ($db['sslmode'] ?? 'prefer'),
        );

        return self::$pdo;
    }

    /**
     * Build a PDO handle. Used by the app and by bin/setup_db.php, which
     * needs to connect as a different role and to a different database.
     */
    public static function connect(
        string $host,
        int $port,
        string $dbname,
        string $user,
        #[\SensitiveParameter] ?string $password,
        string $sslmode = 'prefer',
    ): PDO {
        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException(
                'The pdo_pgsql extension is not loaded. Enable extension=pdo_pgsql in your php.ini.'
            );
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $host,
            $port,
            $dbname,
            $sslmode,
        );

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /**
     * Quote an SQL identifier (table, role, database name). Needed for
     * DDL, where placeholders are not allowed.
     */
    /**
     * Runs $work in a transaction and returns its result. Inside an
     * outer transaction it uses a savepoint, so the work still rolls back
     * as a unit on an exception without ending the outer one.
     *
     * @template T
     * @param callable(PDO): T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo    = self::connection();
        $nested = $pdo->inTransaction();
        $point  = 'sp_' . bin2hex(random_bytes(4));

        $nested ? $pdo->exec("SAVEPOINT {$point}") : $pdo->beginTransaction();

        try {
            $result = $work($pdo);
            $nested ? $pdo->exec("RELEASE SAVEPOINT {$point}") : $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($nested) {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$point}");
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public static function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
