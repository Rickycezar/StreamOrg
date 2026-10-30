<?php
declare(strict_types=1);

/**
 * Copies a new version of a hosted game into public/games/<name>/.
 *
 *     php bin/update_game.php ~/R-Studio/obs/panels/boss_battle/dist/boss-draw
 *
 * The folder is the game's built package (its manifest.json names it). It
 * is checked against the integration contract first (Games::problems): a
 * package this StreamOrg does not understand is refused and nothing
 * changes. The old version is replaced as a whole.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$source = rtrim((string) ($argv[1] ?? ''), '/');

if ($source === '' || !is_file($source . '/manifest.json')) {
    fwrite(STDERR, "Usage: php bin/update_game.php <built package folder with manifest.json>\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($source . '/manifest.json'), true);
$problems = is_array($manifest) ? Games::problems($manifest, $source) : ['manifest.json is not valid JSON'];

if ($problems !== []) {
    fwrite(STDERR, "Refused:\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}

$name   = (string) $manifest['name'];
$target = Games::dir($name);
$old    = is_file($target . '/manifest.json') ? (json_decode((string) file_get_contents($target . '/manifest.json'), true)['version'] ?? '?') : null;

/** Removes a folder and everything in it. */
function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

$staging = $target . '.new';
remove_tree($staging);
mkdir($staging, 0775, true);

$files = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
    $relative = substr($item->getPathname(), strlen($source) + 1);

    if (preg_match('/(^|\/)\.|\.php$|\.phtml$|\.phar$/i', $relative)) {
        fwrite(STDERR, "Skipped (not allowed in a game): {$relative}\n");
        continue;
    }

    $item->isDir() ? @mkdir($staging . '/' . $relative, 0775, true) : copy($item->getPathname(), $staging . '/' . $relative) && $files++;
}

remove_tree($target);
rename($staging, $target);

echo "{$name}: " . ($old !== null ? "{$old} → " : '') . "{$manifest['version']} ({$files} files) in public/games/{$name}/\n";
echo "Changes: " . ($manifest['changes'] ?? '—') . "\n";
