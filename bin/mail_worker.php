<?php
declare(strict_types=1);

/**
 * Sends the e-mails waiting in the outbox (only in "live" mode; in
 * "off" and "preview" it does nothing). Run it every minute, from cron
 * or a systemd timer, once e-mail is plugged in:
 *
 *     php bin/mail_worker.php
 *
 * Each run sends at most a batch; failures are retried by later runs.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$sent = Mailer::deliver(50);

if ($sent > 0) {
    echo "Sent {$sent} e-mail(s).\n";
}
