<?php
declare(strict_types=1);

/**
 * Applies pending SQL migrations from db/migrations in filename order.
 *
 *     php bin/migrate.php            apply pending migrations
 *     php bin/migrate.php --status   list applied / pending, apply nothing
 *
 * Each file runs inside its own transaction: a failing migration rolls back
 * whole, leaving the schema on the last good version. Applied files are
 * recorded in schema_migrations together with a checksum, so an edit to an
 * already-applied migration is reported instead of silently ignored.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

const OK   = "  \033[32m✔\033[0m ";
const WARN = "  \033[33m!\033[0m ";
const INFO = "  \033[34m·\033[0m ";

function fail(string $message): never
{
    fwrite(STDERR, "\n\033[31mMigration failed:\033[0m {$message}\n\n");
    exit(1);
}

$statusOnly = in_array('--status', $argv, true);

$dir = dirname(__DIR__) . '/db/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fail("no .sql files found in {$dir}");
}

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    fail($e->getMessage());
}

$pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS schema_migrations (
        version    text        PRIMARY KEY,
        checksum   text        NOT NULL,
        applied_at timestamptz NOT NULL DEFAULT now()
    )
SQL);

$applied = $pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll();
$applied = array_column($applied, 'checksum', 'version');

$pending = [];
$drift   = [];

foreach ($files as $file) {
    $version  = basename($file, '.sql');
    $checksum = hash('sha256', (string) file_get_contents($file));

    if (!isset($applied[$version])) {
        $pending[] = [$version, $file, $checksum];
    } elseif ($applied[$version] !== $checksum) {
        $drift[] = $version;
    }
}

echo "\nStreamOrg migrations\n";
echo INFO . 'database: ' . Config::get('db.name') . "\n";
echo INFO . count($applied) . ' applied, ' . count($pending) . " pending\n\n";

foreach ($drift as $version) {
    echo WARN . "{$version} was modified after being applied — not re-run\n";
}

if ($statusOnly) {
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        echo isset($applied[$version]) ? OK . $version . "\n" : INFO . "{$version} (pending)\n";
    }
    echo "\n";
    exit(0);
}

if ($pending === []) {
    echo OK . "nothing to do — schema is up to date\n\n";
    exit(0);
}

$record = $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');

foreach ($pending as [$version, $file, $checksum]) {
    $sql = (string) file_get_contents($file);

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $record->execute([$version, $checksum]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail("{$version} rolled back — " . $e->getMessage());
    }

    echo OK . "applied {$version}\n";
}

echo "\n\033[32mDone.\033[0m " . count($pending) . " migration(s) applied.\n\n";
