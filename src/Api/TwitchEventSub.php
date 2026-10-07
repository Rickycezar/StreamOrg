<?php
declare(strict_types=1);

/**
 * Twitch EventSub over webhooks: Twitch calls /twitch/eventsub when a
 * connected channel goes online, goes offline or changes its category,
 * and LiveTracker updates the planned content.
 *
 * Subscriptions are made with the app token and need no extra scope from
 * the user. Every message is signed with a secret derived from APP_KEY
 * (HMAC-SHA256 over id + timestamp + body) and checked before anything
 * else; old or repeated messages are dropped.
 *
 * Twitch only delivers to public HTTPS addresses on port 443, so this is
 * unavailable when the base URL is localhost.
 */
final class TwitchEventSub
{
    /** Subscription type => version. */
    public const TYPES = [
        'stream.online'  => '1',
        'stream.offline' => '1',
        'channel.update' => '2',
    ];

    /** Messages older than this are refused, as Twitch recommends. */
    private const MAX_AGE = 600;

    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /** Where Twitch should deliver, or null when it cannot reach this install. */
    public static function callbackUrl(): ?string
    {
        $base  = rtrim((string) Config::get('app.base_url', ''), '/');
        $parts = parse_url($base);

        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['port'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $base . '/twitch/eventsub';
    }

    public static function isAvailable(): bool
    {
        return Twitch::isConfigured() && self::callbackUrl() !== null;
    }

    public static function secret(): string
    {
        return hash_hmac('sha256', 'twitch-eventsub', (string) Config::get('security.app_key', ''));
    }

    /**
     * Creates the subscriptions for a user's channel (an existing one is
     * adopted). True when all of them exist afterwards.
     */
    public static function subscribe(int $userId): bool
    {
        $conn     = TwitchUser::connection($userId);
        $callback = self::callbackUrl();

        if ($conn === null || $callback === null || !Twitch::isConfigured()) {
            self::$lastError = 'unavailable';
            return false;
        }

        $ok = true;

        foreach (self::TYPES as $type => $version) {
            $response = Twitch::app('POST', 'eventsub/subscriptions', [], [
                'type'      => $type,
                'version'   => $version,
                'condition' => ['broadcaster_user_id' => $conn['twitch_user_id']],
                'transport' => ['method' => 'webhook', 'callback' => $callback, 'secret' => self::secret()],
            ]);

            $data = Http::json($response);

            if ($data !== null && isset($data['data'][0]['id'])) {
                self::store($userId, $data['data'][0]);
                continue;
            }

            if ($response['status'] === 409) {
                foreach (self::existing($conn['twitch_user_id']) as $sub) {
                    if ($sub['type'] === $type) {
                        self::store($userId, $sub);
                    }
                }
                continue;
            }

            self::$lastError = Twitch::describe($response, 'eventsub/subscriptions');
            $ok = false;
        }

        return $ok;
    }

    /** Deletes the user's subscriptions at Twitch (best effort) and here. */
    public static function unsubscribe(int $userId): void
    {
        $ids = array_column(self::subscriptions($userId), 'id');
        $conn = TwitchUser::connection($userId);

        if ($conn !== null && Twitch::isConfigured()) {
            foreach (self::existing($conn['twitch_user_id']) as $sub) {
                $ids[] = $sub['id'];
            }
        }

        if (Twitch::isConfigured()) {
            foreach (array_unique($ids) as $id) {
                Twitch::app('DELETE', 'eventsub/subscriptions', ['id' => $id]);
            }
        }

        Database::connection()->prepare('DELETE FROM twitch_eventsub_subscriptions WHERE user_id = ?')->execute([$userId]);
    }

    /** @return list<array{id:string, type:string, status:string, created_at:string}> */
    public static function subscriptions(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, type, status, created_at FROM twitch_eventsub_subscriptions WHERE user_id = ? ORDER BY type'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /** Whether all subscriptions exist and Twitch has not given up on any. */
    public static function isActive(int $userId): bool
    {
        $live = array_filter(
            self::subscriptions($userId),
            static fn (array $s): bool => in_array($s['status'], ['enabled', 'webhook_callback_verification_pending'], true)
        );

        return count(array_unique(array_column($live, 'type'))) === count(self::TYPES);
    }

    /** Checks a delivery's signature and age. */
    public static function verify(string $id, string $timestamp, string $signature, string $body, ?int $now = null): bool
    {
        $sent = self::parseTime($timestamp);

        if ($id === '' || $sent === null || abs(($now ?? time()) - $sent->getTimestamp()) > self::MAX_AGE) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $id . $timestamp . $body, self::secret());

        return hash_equals($expected, $signature);
    }

    /**
     * Handles one verified delivery and returns the response to send:
     * [status, content type, body].
     *
     * @return array{0:int, 1:string, 2:string}
     */
    public static function handle(string $messageId, string $messageType, string $timestamp, array $payload): array
    {
        $pdo          = Database::connection();
        $subscription = (array) ($payload['subscription'] ?? []);

        if ($messageType === 'webhook_callback_verification') {
            self::setStatus((string) ($subscription['id'] ?? ''), 'enabled');
            return [200, 'text/plain', (string) ($payload['challenge'] ?? '')];
        }

        if ($messageType === 'revocation') {
            self::setStatus((string) ($subscription['id'] ?? ''), (string) ($subscription['status'] ?? 'revoked'));
            return [204, 'text/plain', ''];
        }

        if ($messageType !== 'notification') {
            return [204, 'text/plain', ''];
        }

        $stmt = $pdo->prepare('INSERT INTO twitch_eventsub_messages (id) VALUES (?) ON CONFLICT (id) DO NOTHING');
        $stmt->execute([$messageId]);

        if ($stmt->rowCount() === 0) {
            return [204, 'text/plain', ''];
        }

        $pdo->exec("DELETE FROM twitch_eventsub_messages WHERE received_at < now() - interval '2 days'");

        $event = (array) ($payload['event'] ?? []);
        $at    = self::parseTime($timestamp) ?? new DateTimeImmutable();
        $users = $pdo->prepare('SELECT user_id FROM twitch_connections WHERE twitch_user_id = ?');
        $users->execute([(string) ($event['broadcaster_user_id'] ?? '')]);

        foreach ($users->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $pdo->beginTransaction();

            try {
                self::dispatch((int) $userId, (string) ($subscription['type'] ?? ''), $event, $at);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                ErrorLog::note('EventSub: ' . $e->getMessage());
            }
        }

        return [204, 'text/plain', ''];
    }

    private static function dispatch(int $userId, string $type, array $event, DateTimeImmutable $at): void
    {
        switch ($type) {
            case 'stream.online':
                if (($event['type'] ?? 'live') !== 'live') {
                    return;
                }

                $channel = self::channel((string) ($event['broadcaster_user_id'] ?? ''));
                LiveTracker::online(
                    $userId,
                    self::parseTime((string) ($event['started_at'] ?? '')) ?? $at,
                    $channel['game_id'] ?? null,
                    $channel['game_name'] ?? null
                );
                return;

            case 'stream.offline':
                LiveTracker::offline($userId, $at);
                return;

            case 'channel.update':
                LiveTracker::categoryChanged(
                    $userId,
                    (string) ($event['category_id'] ?? ''),
                    (string) ($event['category_name'] ?? ''),
                    $at
                );
                return;
        }
    }

    /**
     * The channel's current category, read with the app token: the user's
     * own token is not needed for public channel data, and a failed
     * refresh here must not disconnect them.
     *
     * @return array{game_id:string, game_name:string}|null
     */
    private static function channel(string $broadcasterId): ?array
    {
        if ($broadcasterId === '' || !Twitch::isConfigured()) {
            return null;
        }

        $row = Http::json(Twitch::app('GET', 'channels', ['broadcaster_id' => $broadcasterId]))['data'][0] ?? null;

        return is_array($row) ? ['game_id' => (string) ($row['game_id'] ?? ''), 'game_name' => (string) ($row['game_name'] ?? '')] : null;
    }

    /** Twitch's RFC 3339 times carry nanoseconds; PHP takes microseconds. */
    private static function parseTime(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/(\.\d{6})\d+/', '$1', $value);

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /** @return list<array{id:string, type:string, status:string}> this app's subscriptions for a channel */
    private static function existing(string $broadcasterId): array
    {
        $data = Http::json(Twitch::app('GET', 'eventsub/subscriptions', ['user_id' => $broadcasterId]));

        return array_values(array_filter(
            (array) ($data['data'] ?? []),
            static fn ($s): bool => is_array($s) && isset(self::TYPES[$s['type'] ?? ''])
                && ($s['transport']['callback'] ?? null) === self::callbackUrl()
        ));
    }

    private static function store(int $userId, array $sub): void
    {
        Database::connection()->prepare(
            'INSERT INTO twitch_eventsub_subscriptions (id, user_id, type, status) VALUES (?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET user_id = EXCLUDED.user_id, status = EXCLUDED.status'
        )->execute([(string) $sub['id'], $userId, (string) $sub['type'], (string) ($sub['status'] ?? 'enabled')]);
    }

    private static function setStatus(string $id, string $status): void
    {
        if ($id !== '') {
            Database::connection()->prepare('UPDATE twitch_eventsub_subscriptions SET status = ? WHERE id = ?')
                ->execute([$status, $id]);
        }
    }
}
