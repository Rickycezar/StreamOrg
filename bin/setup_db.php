<?php
declare(strict_types=1);

/**
 * Provisions the PostgreSQL role and database described in config/config.php.
 *
 *     php bin/setup_db.php
 *
 * Idempotent: re-running it on an existing setup makes no destructive change.
 * It does NOT create tables — schema comes later, via migrations.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

const OK   = "  \033[32m✔\033[0m ";
const INFO = "  \033[34m·\033[0m ";

function fail(string $message): never
{
    fwrite(STDERR, "\n\033[31mSetup failed:\033[0m {$message}\n\n");
    exit(1);
}

$config = Config::load();

$host     = (string) Config::get('db.host', '127.0.0.1');
$port     = (int)    Config::get('db.port', 5432);
$sslmode  = (string) Config::get('db.sslmode', 'prefer');
$dbName   = (string) Config::get('db.name', '');
$dbUser   = (string) Config::get('db.user', '');
$dbPass   = Config::get('db.password');

$adminUser = (string) Config::get('db_admin.user', 'postgres');
$adminPass = Config::get('db_admin.password');
$adminDb   = (string) Config::get('db_admin.maintenance_db', 'postgres');

if ($dbName === '' || $dbUser === '') {
    fail('db.name and db.user must be set in config/config.php.');
}

if (!is_string($dbPass) || $dbPass === '' || $dbPass === 'CHANGE_ME') {
    fail('db.password is still unset or left at CHANGE_ME in config/config.php.');
}

if ($adminPass === null && getenv('PGPASSWORD') !== false) {
    $adminPass = (string) getenv('PGPASSWORD');
}

echo "\nStreamOrg database setup\n";
echo INFO . "server:   {$host}:{$port}\n";
echo INFO . "database: {$dbName}\n";
echo INFO . "role:     {$dbUser}\n";
echo INFO . "admin:    {$adminUser} (via {$adminDb})\n\n";

try {
    $admin = Database::connect($host, $port, $adminDb, $adminUser, $adminPass, $sslmode);
} catch (Throwable $e) {
    fail("could not connect as '{$adminUser}': " . $e->getMessage());
}

echo OK . "connected as {$adminUser}\n";

$quotedDb   = Database::quoteIdentifier($dbName);
$quotedUser = Database::quoteIdentifier($dbUser);

$stmt = $admin->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
$stmt->execute([$dbUser]);

if ($stmt->fetchColumn() === false) {
    $admin->exec(sprintf(
        'CREATE ROLE %s WITH LOGIN PASSWORD %s',
        $quotedUser,
        $admin->quote($dbPass),
    ));
    echo OK . "created role {$dbUser}\n";
} else {
    $admin->exec(sprintf(
        'ALTER ROLE %s WITH LOGIN PASSWORD %s',
        $quotedUser,
        $admin->quote($dbPass),
    ));
    echo OK . "role {$dbUser} already existed — password synced with config\n";
}

$stmt = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
$stmt->execute([$dbName]);

if ($stmt->fetchColumn() === false) {
    $admin->exec(sprintf(
        "CREATE DATABASE %s WITH OWNER %s ENCODING 'UTF8' TEMPLATE template0",
        $quotedDb,
        $quotedUser,
    ));
    echo OK . "created database {$dbName}\n";
} else {
    echo OK . "database {$dbName} already existed — left untouched\n";
}

try {
    $target = Database::connect($host, $port, $dbName, $adminUser, $adminPass, $sslmode);
} catch (Throwable $e) {
    fail("could not connect to '{$dbName}' as admin: " . $e->getMessage());
}

$target->exec(sprintf('ALTER SCHEMA public OWNER TO %s', $quotedUser));
$target->exec(sprintf('GRANT ALL ON SCHEMA public TO %s', $quotedUser));
$target->exec(sprintf('GRANT CONNECT ON DATABASE %s TO %s', $quotedDb, $quotedUser));
echo OK . "granted {$dbUser} ownership of schema public\n";

try {
    $app = Database::connect($host, $port, $dbName, $dbUser, $dbPass, $sslmode);
    $version = $app->query('SELECT version()')->fetchColumn();
} catch (Throwable $e) {
    fail("role '{$dbUser}' cannot connect to '{$dbName}': " . $e->getMessage()
        . "\n  Check pg_hba.conf allows password auth for this role from {$host}.");
}

echo OK . "verified {$dbUser} can connect\n";
echo INFO . substr((string) $version, 0, 60) . "\n";

echo "\n\033[32mDone.\033[0m No tables created yet — define the schema next.\n\n";
