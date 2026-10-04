<?php
declare(strict_types=1);

/**
 * Creates or updates an application user.
 *
 *     php bin/create_user.php <username> [--admin] [--email=] [--name=]
 *                            [--channel=twitch:handle] [--locale=] [--tz=]
 *                            [--password=] [--reset-private-vault]
 *
 * With no --password a strong one is generated and printed once. Re-running
 * for an existing username updates that account rather than failing.
 *
 * Updating an existing user is a password reset, so it signs out every one
 * of their sessions.
 *
 * Updating a user whose key vault is private sets a password without the
 * old one, which makes their stored key codes unreadable for good. That
 * refuses to run unless --reset-private-vault says it is intended.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

function fail(string $message): never
{
    fwrite(STDERR, "\n\033[31mError:\033[0m {$message}\n\n");
    exit(1);
}

$args     = array_slice($argv, 1);
$username = null;
$options  = [];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $options[$key] = $value;
    } elseif ($username === null) {
        $username = $arg;
    }
}

if ($username === null) {
    fail('Usage: php bin/create_user.php <username> [--admin] [--email=…] [--channel=twitch:handle]');
}

$password  = is_string($options['password'] ?? null)
    ? $options['password']
    : bin2hex(random_bytes(9));
$generated = !is_string($options['password'] ?? null);

$email  = is_string($options['email'] ?? null) ? $options['email'] : $username . '@localhost';
$name   = is_string($options['name'] ?? null) ? $options['name'] : $username;
$locale = is_string($options['locale'] ?? null) ? $options['locale'] : 'en';
$tz     = is_string($options['tz'] ?? null) ? $options['tz'] : 'UTC';
$role   = isset($options['admin']) ? 'admin' : 'user';

$platformId = null;
$handle     = null;

if (is_string($options['channel'] ?? null)) {
    [$platformCode, $handle] = array_pad(explode(':', $options['channel'], 2), 2, null);

    $stmt = Database::connection()->prepare('SELECT id FROM streaming_platforms WHERE code = ? AND is_enabled');
    $stmt->execute([$platformCode]);
    $platformId = $stmt->fetchColumn();

    if ($platformId === false) {
        fail("Unknown streaming platform '{$platformCode}'.");
    }
}

$stmt = Database::connection()->prepare('SELECT vault_mode FROM users WHERE username = ?');
$stmt->execute([$username]);
$privateVault = $stmt->fetchColumn() === 'private';

if ($privateVault && !isset($options['reset-private-vault'])) {
    fail("'{$username}' has a private key vault. Setting a new password makes every key code they\n"
       . "       have stored unreadable, permanently — nobody can recover them.\n"
       . "       Re-run with --reset-private-vault if that is really what you want.");
}

$pdo = Database::connection();
$pdo->beginTransaction();

$stmt = $pdo->prepare(
    'INSERT INTO users (username, email, password_hash, display_name, locale,
                        timezone, role, channel_platform_id, channel_handle)
     VALUES (:username, :email, :hash, :name, :locale, :tz, :role, :platform, :handle)
     ON CONFLICT (username) DO UPDATE
        SET email               = EXCLUDED.email,
            password_hash       = EXCLUDED.password_hash,
            display_name        = EXCLUDED.display_name,
            locale              = EXCLUDED.locale,
            timezone            = EXCLUDED.timezone,
            role                = EXCLUDED.role,
            channel_platform_id = EXCLUDED.channel_platform_id,
            channel_handle      = EXCLUDED.channel_handle
     RETURNING id, (xmax = 0) AS created'
);

$stmt->execute([
    'username' => $username,
    'email'    => $email,
    'hash'     => Auth::hash($password),
    'name'     => $name,
    'locale'   => $locale,
    'tz'       => $tz,
    'role'     => $role,
    'platform' => $platformId ?: null,
    'handle'   => $handle,
]);

$row = $stmt->fetch();

if ($privateVault) {
    Vault::resetPrivate((int) $row['id'], $password);
}

$ended = $row['created'] ? 0 : UserSessions::revokeAll((int) $row['id'], 'password_reset');

$pdo->commit();

echo "\n\033[32m" . ($row['created'] ? 'Created' : 'Updated') . "\033[0m user #{$row['id']}\n";
echo "  username: {$username}\n";
echo "  role:     {$role}\n";
echo "  locale:   {$locale}   timezone: {$tz}\n";

if ($handle !== null) {
    echo "  channel:  {$options['channel']}\n";
}

if ($ended > 0) {
    echo "  sessions: {$ended} signed out\n";
}

if ($privateVault) {
    echo "  \033[33mvault:    private — reset; previously stored key codes are now unreadable\033[0m\n";
}

if ($generated) {
    echo "\n  \033[33mpassword: {$password}\033[0m\n";
    echo "  Shown once — store it now.\n";
}

echo "\n";
