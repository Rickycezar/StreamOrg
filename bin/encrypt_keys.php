<?php
declare(strict_types=1);

/**
 * Encrypts key codes still stored as plain text.
 *
 *     php bin/encrypt_keys.php            encrypt every pending code
 *     php bin/encrypt_keys.php --status   count pending codes, change nothing
 *
 * Run once after migration 015_key_vault. Safe to re-run: only rows without
 * a key_hash are touched, and each user's rows convert in one transaction.
 *
 * Every user starts with a managed vault, so this needs no passwords. A
 * user who already went private before their rows were converted is asked
 * for theirs.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

const OK   = "  \033[32m✔\033[0m ";
const INFO = "  \033[34m·\033[0m ";
const BAD  = "  \033[31m✗\033[0m ";

$statusOnly = in_array('--status', $argv, true);
$pdo = Database::connection();

$users = $pdo->query(
    'SELECT u.id, u.username, count(*) AS pending
       FROM game_keys k
       JOIN users u ON u.id = k.user_id
      WHERE k.key_hash IS NULL
   GROUP BY u.id, u.username
   ORDER BY u.id'
)->fetchAll();

echo "\nStreamOrg key encryption\n";

if ($users === []) {
    echo OK . "nothing to encrypt\n\n";
    exit(0);
}

$failed = false;

foreach ($users as $user) {
    $id = (int) $user['id'];

    if ($statusOnly) {
        echo INFO . "{$user['username']}: {$user['pending']} plain-text code(s)\n";
        continue;
    }

    if (!Vault::promptUnlock($id, (string) $user['username'])) {
        echo BAD . "{$user['username']}: wrong password, skipped\n";
        $failed = true;
        continue;
    }

    $pdo->beginTransaction();

    try {
        $rows = $pdo->prepare('SELECT id, key_code FROM game_keys WHERE user_id = ? AND key_hash IS NULL FOR UPDATE');
        $rows->execute([$id]);

        $update = $pdo->prepare('UPDATE game_keys SET key_code = ?, key_hash = ? WHERE id = ?');
        $count  = 0;

        foreach ($rows->fetchAll() as $row) {
            $plain = (string) $row['key_code'];

            if (Vault::isEncrypted($plain)) {
                $plain = Vault::decryptCode($id, $plain);

                if ($plain === null) {
                    throw new RuntimeException("key #{$row['id']} is encrypted but unreadable");
                }
            }

            $update->execute([Vault::encryptCode($id, $plain), Vault::hashCode($id, $plain), $row['id']]);
            $count++;
        }

        $pdo->commit();
        echo OK . "{$user['username']}: {$count} code(s) encrypted\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo BAD . "{$user['username']}: {$e->getMessage()} — nothing changed for this user\n";
        $failed = true;
    }
}

echo "\n";
exit($failed ? 1 : 0);
