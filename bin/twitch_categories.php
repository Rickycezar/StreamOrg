<?php
declare(strict_types=1);

/**
 * Looks up the Twitch category of every game still on the Just Chatting
 * stand-in (those Twitch did not list, or that were stored before every
 * game had a category).
 *
 *     php bin/twitch_categories.php
 *
 * Run in the background on each container start; the admin's "Find
 * Twitch categories" button does the same, one batch at a time. A game
 * looked up within the last hour is skipped.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$_SESSION = [];

if (!Twitch::isConfigured()) {
    echo "StreamOrg: Twitch is not configured; game categories stay as they are.\n";
    exit(0);
}

$pdo   = Database::connection();
$found = 0;
$seen  = 0;

do {
    $batch  = TwitchCategories::fillMissing($pdo, true);
    $found += $batch['found'];
    $seen  += $batch['checked'];
} while ($batch['checked'] > 0 && $batch['left'] > 0);

printf("StreamOrg: Twitch categories found for %d of %d game(s); %d on Just Chatting.\n", $found, $seen, TwitchCategories::defaults($pdo));
