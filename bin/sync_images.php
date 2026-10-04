<?php
declare(strict_types=1);

/**
 * Downloads game artwork into public/media/games/.
 *
 *     php bin/sync_images.php               games missing any artwork
 *     php bin/sync_images.php --all         every game, skipping unchanged files
 *     php bin/sync_images.php --all --force every game, downloading everything again
 *     php bin/sync_images.php --game=12     one game (implies --force)
 *
 * Imports and catalogue refreshes keep artwork current on their own; this
 * is for games stored before images were kept locally, or to repair files.
 * Asks the provider for each game again, so it pauses between Steam calls
 * to stay inside Steam's rate limit.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

const OK   = "  \033[32m✔\033[0m ";
const SKIP = "  \033[33m·\033[0m ";
const BAD  = "  \033[31m✗\033[0m ";

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    [$k, $v] = array_pad(explode('=', ltrim($arg, '-'), 2), 2, true);
    $options[$k] = $v;
}

$pdo   = Database::connection();
$force = isset($options['force']) || isset($options['game']);

$sql = 'SELECT id, title, source_provider, source_ref FROM games
         WHERE source_provider IS NOT NULL AND source_ref IS NOT NULL';

if (isset($options['game'])) {
    $sql .= ' AND id = ' . (int) $options['game'];
} elseif (!isset($options['all'])) {
    $sql .= " AND NOT EXISTS (SELECT 1 FROM game_images i WHERE i.game_id = games.id AND i.kind = 'thumb')";
}

$games = $pdo->query($sql . ' ORDER BY id')->fetchAll();

echo "\nStreamOrg game images — " . count($games) . " game(s)\n\n";

$totals = ['downloaded' => 0, 'failed' => 0];

foreach ($games as $i => $game) {
    $provider = Providers::get((string) $game['source_provider']);

    if ($provider === null || !$provider->isAvailable()) {
        echo SKIP . "#{$game['id']} {$game['title']} — provider {$game['source_provider']} unavailable\n";
        continue;
    }

    if ($i > 0 && $game['source_provider'] === 'steam') {
        usleep(1_500_000);
    }

    try {
        $detail = $provider->fetch((string) $game['source_ref']);
    } catch (Throwable $e) {
        $detail = null;
    }

    if ($detail === null) {
        echo BAD . "#{$game['id']} {$game['title']} — provider returned nothing\n";
        continue;
    }

    $result = GameImages::sync($pdo, (int) $game['id'], $detail['images'] ?? [], $force);
    $totals['downloaded'] += count($result['downloaded']);
    $totals['failed']     += count($result['failed']);

    echo ($result['failed'] === [] ? OK : SKIP) . "#{$game['id']} {$game['title']}"
        . ($result['downloaded'] ? ' — got ' . implode(', ', $result['downloaded']) : '')
        . ($result['kept'] ? ' — unchanged ' . implode(', ', $result['kept']) : '')
        . ($result['failed'] ? ' — none for ' . implode(', ', $result['failed']) : '')
        . "\n";
}

echo "\n" . OK . "{$totals['downloaded']} file(s) downloaded, {$totals['failed']} not available\n\n";
