<?php
declare(strict_types=1);

/**
 * Reads server logs (the output of `docker logs --timestamps`) on standard
 * input and keeps the problems found in them in the error log, marked as
 * read back from the logs. For the errors written before the error log
 * existed; running it twice on the same lines counts them twice.
 *
 * Usage: docker logs --timestamps <container> 2>&1 | php bin/errors_backfill.php
 *
 * Kept: PHP fatal errors, warnings, notices and deprecations, requests
 * that failed ("StreamOrg: …") and problems the code noted
 * ("StreamOrg <what>: …"). Left out: probes for files that do not exist
 * (wp-login.php and the like) and other web server noise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

Config::load();

$kept    = 0;
$skipped = 0;

while (($raw = fgets(STDIN)) !== false) {
    $raw = rtrim($raw, "\r\n");

    if (!preg_match('/^(\d{4}-\d\d-\d\dT[\d:.]+Z)\s+(.*)$/', $raw, $m)) {
        continue;
    }

    [$at, $line] = [$m[1], $m[2]];
    $referer     = null;

    if (preg_match('/^\[[^\]]+\] \[php:\w+\] \[pid [^\]]+\](?: \[client [^\]]+\])? (.*)$/', $line, $apache)) {
        $line = $apache[1];

        if (preg_match('/^(.*), referer: (\S+)$/', $line, $r)) {
            [$line, $referer] = [$r[1], parse_url($r[2], PHP_URL_PATH) ?: null];
        }
    } elseif (!str_starts_with($line, 'PHP ')) {
        continue;
    }

    $file = null;
    $num  = null;

    if (preg_match('/^PHP (Fatal error|Parse error|Warning|Notice|Deprecated):\s+(.*)$/', $line, $php)) {
        $level   = ['Fatal error' => 'fatal', 'Parse error' => 'fatal', 'Warning' => 'warning'][$php[1]] ?? 'notice';
        $message = $php[2];

        if (preg_match('/^(.*) in (\/\S+?)(?::| on line )(\d+)$/', $message, $where)) {
            [$message, $file, $num] = [$where[1], $where[2], (int) $where[3]];
        }
    } elseif (preg_match('/^StreamOrg: (.*)$/', $line, $failed)) {
        $level   = 'error';
        $message = $failed[1];

        if (preg_match('/^(.*) in (\/\S+):(\d+)$/', $message, $where)) {
            [$message, $file, $num] = [$where[1], $where[2], (int) $where[3]];
        }
    } elseif (preg_match('/^StreamOrg (.+)$/', $line, $noted) && !str_starts_with($noted[1], 'migrations')) {
        $level   = 'notice';
        $message = $noted[1];
    } else {
        $skipped++;
        continue;
    }

    $id = ErrorLog::record($level, $message, $file !== null ? str_replace('/var/www/html/', '', $file) : null, $num, null, [
        'at'      => $at,
        'source'  => 'import',
        'referer' => $referer,
        'notify'  => false,
    ]);

    $id !== null ? $kept++ : $skipped++;
}

echo "Kept {$kept} problem line(s); left out {$skipped}.\n";
