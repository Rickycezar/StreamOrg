<?php
declare(strict_types=1);

/**
 * StreamOrg's e-mail: every message is queued (email_outbox), rendered
 * from its template in the recipient's language, and only a worker
 * (bin/mail_worker.php) hands queued ones to the provider.
 *
 * Modes (Administration → E-mails), "off" by default:
 *   off      nothing is queued (only the administrators' test e-mails)
 *   preview  everything is queued and stops as "held": visible in the
 *            outbox as it would arrive, nothing leaves the server
 *   live     queued e-mails are sent by the worker; needs a provider
 *
 * The pages people use because of e-mail (forgot password, the links'
 * pages, the profile's confirmation and notification switch) exist only
 * when e-mail really goes out: live, with a working provider (ready()).
 *
 * Rules before anything is queued: a valid address not on the
 * suppression list; messages marked "verified" (notification copies) only
 * to confirmed addresses; "optional" ones only to people who kept them on;
 * at most PER_HOUR e-mails per address per hour and the daily cap overall.
 * E-mails come only from the fixed templates, and every value put into
 * one is escaped (EmailRenderer).
 */
final class Mailer
{
    public const MODES = ['off', 'preview', 'live'];

    public const PER_HOUR = 8;

    public const PER_DAY = 2000;

    public const MAX_ATTEMPTS = 5;

    /** How a queued e-mail is handed over (tests replace it); the provider's when null. */
    public static ?MailTransport $transport = null;

    public static function mode(): string
    {
        $mode = (string) Settings::get('mail.mode', 'off');

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    /** Whether StreamOrg prepares e-mails now (preview or live). */
    public static function on(): bool
    {
        return self::mode() !== 'off';
    }

    /**
     * Whether e-mail really goes out (live, with the provider set up): only
     * then do the pages that depend on it show.
     */
    public static function ready(): bool
    {
        return self::mode() === 'live' && PostmarkTransport::fromSettings() !== null;
    }

    /** @return array{name:string, address:string, reply_to:string} who the e-mails come from */
    public static function sender(): array
    {
        return [
            'name'     => (string) Settings::get('mail.from_name', 'StreamOrg'),
            'address'  => (string) Settings::get('mail.from_address', ''),
            'reply_to' => (string) Settings::get('mail.reply_to', ''),
        ];
    }

    /**
     * Queues a system message to an account (in its language, to its
     * address) or to an address. Null when a rule kept it from being
     * queued; the outbox id otherwise (also when the address is
     * suppressed: it is recorded as such).
     *
     * @param array{user?:array, address?:string, locale?:string} $to
     * @param array<string, string|int> $values the template's placeholders
     */
    public static function queue(string $code, array $to, array $values = [], ?string $link = null, bool $test = false): ?int
    {
        if (!EmailTemplates::exists($code) || (self::mode() === 'off' && !$test)) {
            return null;
        }

        $rule    = EmailTemplates::CODES[$code];
        $user    = $to['user'] ?? null;
        $address = strtolower(trim((string) ($to['address'] ?? ($user['email'] ?? ''))));
        $locale  = (string) ($to['locale'] ?? ($user['locale'] ?? Config::get('app.locale', 'en')));
        $locale  = in_array($locale, Lang::available(), true) ? $locale : 'en';

        if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL) || strlen($address) > 254) {
            return null;
        }
        if ($user !== null && $rule['verified'] && empty($user['email_verified_at'])) {
            return null;
        }
        if ($user !== null && $rule['optional'] && isset($user['email_notifications']) && !$user['email_notifications']) {
            return null;
        }
        if (!$test && !self::withinLimits($address)) {
            ErrorLog::note('mail: limit reached, not queued: ' . $code);
            return null;
        }

        $values += ['name' => (string) ($user['display_name'] ?? '') ?: (string) ($user['username'] ?? '')];
        $unsubscribe = $rule['optional'] && $user !== null ? self::unsubscribeUrl((int) $user['id'], $locale) : null;
        $message     = EmailRenderer::render($code, $locale, $values, $link, $unsubscribe);
        $status      = self::suppressed($address) ? 'suppressed' : (self::mode() === 'live' ? 'queued' : 'held');

        $stmt = Database::connection()->prepare(
            'INSERT INTO email_outbox (user_id, to_address, locale, template, subject, html, body_text, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$user['id'] ?? null, $address, $locale, $code, $message['subject'], $message['html'], $message['text'], $status]);

        return (int) $stmt->fetchColumn();
    }

    /** Whether an address may get another e-mail now (PER_HOUR to it, PER_DAY overall). */
    private static function withinLimits(string $address): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT count(*) FILTER (WHERE to_address = ? AND created_at > now() - interval '1 hour'),
                    count(*) FILTER (WHERE created_at > now() - interval '1 day')
               FROM email_outbox WHERE created_at > now() - interval '1 day' AND status <> 'suppressed'"
        );
        $stmt->execute([$address]);
        [$hour, $day] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));

        return $hour < self::PER_HOUR && $day < (int) Settings::get('mail.per_day', (string) self::PER_DAY);
    }

    public static function suppressed(string $address): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM email_suppressions WHERE address = ?');
        $stmt->execute([$address]);

        return (bool) $stmt->fetchColumn();
    }

    /** Never writes to an address again (a bounce, a complaint, or by hand). */
    public static function suppress(string $address, string $reason): void
    {
        Database::connection()->prepare(
            'INSERT INTO email_suppressions (address, reason) VALUES (?, ?) ON CONFLICT (address) DO UPDATE SET reason = excluded.reason'
        )->execute([strtolower(trim($address)), in_array($reason, ['bounce', 'complaint', 'manual'], true) ? $reason : 'manual']);
    }

    /** A link that turns a person's notification e-mails off in one click, signed with the app key. */
    public static function unsubscribeUrl(int $userId, string $locale): string
    {
        return rtrim(public_base_url($locale), '/') . url('/email/unsubscribe') . '?u=' . $userId . '&s=' . self::signature('unsubscribe:' . $userId);
    }

    /** Whether an unsubscribe link's signature is right. */
    public static function validUnsubscribe(int $userId, string $signature): bool
    {
        return $userId > 0 && hash_equals(self::signature('unsubscribe:' . $userId), $signature);
    }

    private static function signature(string $what): string
    {
        return substr(hash_hmac('sha256', $what, 'streamorg-mail|' . (string) Config::get('security.app_key', '')), 0, 32);
    }

    /**
     * Sends what is queued (live mode only), oldest first, a few at a
     * time; a failure is retried later with a growing wait, up to
     * MAX_ATTEMPTS, and a permanent refusal suppresses the address.
     *
     * @return int how many were sent
     */
    public static function deliver(int $limit = 20): int
    {
        if (self::mode() !== 'live') {
            return 0;
        }

        $transport = self::$transport ?? PostmarkTransport::fromSettings();

        if ($transport === null) {
            return 0;
        }

        $pdo  = Database::connection();
        $rows = Database::transaction(static function (PDO $pdo) use ($limit): array {
            $stmt = $pdo->prepare(
                "SELECT * FROM email_outbox WHERE status = 'queued' AND send_after <= now() ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED"
            );
            $stmt->execute([$limit]);
            $rows = $stmt->fetchAll();

            if ($rows !== []) {
                $pdo->exec("UPDATE email_outbox SET status = 'sending', attempts = attempts + 1 WHERE id IN (" . implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows)) . ')');
            }

            return $rows;
        });

        $sent = 0;

        foreach ($rows as $row) {
            $result = $transport->send($row + ['sender' => self::sender(), 'unsubscribe' => self::unsubscribeFor($row)]);

            if ($result['ok']) {
                $pdo->prepare("UPDATE email_outbox SET status = 'sent', sent_at = now(), provider_id = ?, last_error = NULL WHERE id = ?")
                    ->execute([$result['id'] ?? null, $row['id']]);
                $sent++;
                continue;
            }

            if (!empty($result['permanent'])) {
                self::suppress((string) $row['to_address'], 'bounce');
            }

            $final = !empty($result['permanent']) || (int) $row['attempts'] + 1 >= self::MAX_ATTEMPTS;
            $pdo->prepare(
                "UPDATE email_outbox SET status = ?, last_error = ?, send_after = now() + make_interval(mins => ?) WHERE id = ?"
            )->execute([$final ? 'failed' : 'queued', mb_substr((string) ($result['error'] ?? 'error'), 0, 500), 2 ** ((int) $row['attempts'] + 1), $row['id']]);
        }

        return $sent;
    }

    /** The unsubscribe link of a queued notification copy, for the List-Unsubscribe header. */
    private static function unsubscribeFor(array $row): ?string
    {
        return ($row['user_id'] ?? null) !== null && (EmailTemplates::CODES[$row['template']]['optional'] ?? false)
            ? self::unsubscribeUrl((int) $row['user_id'], (string) $row['locale'])
            : null;
    }

    /**
     * The outbox, newest first, without the bodies.
     *
     * @return list<array>
     */
    public static function recent(int $limit = 100, ?string $status = null): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT o.id, o.to_address, o.locale, o.template, o.subject, o.status, o.attempts, o.last_error, o.created_at, o.sent_at, u.username
               FROM email_outbox o LEFT JOIN users u ON u.id = o.user_id'
            . ($status !== null ? ' WHERE o.status = ?' : '') . ' ORDER BY o.id DESC LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute($status !== null ? [$status] : []);

        return $stmt->fetchAll();
    }

    /** @return array<string, int> how many e-mails are in each state */
    public static function counts(): array
    {
        return array_map('intval', Database::connection()->query('SELECT status, count(*) FROM email_outbox GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
