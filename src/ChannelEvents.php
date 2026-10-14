<?php
declare(strict_types=1);

/**
 * What happened in a streamer's channel, as the chat bot recorded it
 * (channel_events, see 047_bot_giveaways_events.sql): follows,
 * subscriptions and gifts, bits, raids, watch streaks, charity; kept 90
 * days. Stream tools → Events lists them.
 *
 * Unfollows come from the bot's daily look at the follower list, which
 * runs only for the channels an administrator switched it on for
 * (follower_tracking) while the administrators' setting unfollows.enabled
 * is on. Stream tools → Unfollows exists only then.
 */
final class ChannelEvents
{
    /** Filters on the Events page: name => event types. */
    public const FILTERS = [
        'follows' => ['follow'],
        'subs'    => ['sub', 'resub', 'gift_sub', 'community_gift', 'gift_upgrade', 'prime_upgrade'],
        'bits'    => ['cheer', 'bits_badge'],
        'raids'   => ['raid'],
        'streaks' => ['watch_streak'],
        'charity' => ['charity'],
    ];

    public const PER_PAGE = 50;

    private const SETTING = 'unfollows.enabled';

    /**
     * A page of a channel's events, newest first: every type but
     * unfollows, or the types of one filter, or only unfollows.
     *
     * @return array{rows:list<array>, total:int}
     */
    public static function page(int $userId, ?string $filter, int $page = 1): array
    {
        $types = $filter === 'unfollows' ? ['unfollow'] : (self::FILTERS[$filter] ?? array_merge(...array_values(self::FILTERS)));
        $marks = implode(',', array_fill(0, count($types), '?'));
        $pdo   = Database::connection();

        $count = $pdo->prepare("SELECT count(*) FROM channel_events WHERE user_id = ? AND type IN ({$marks})");
        $count->execute([$userId, ...$types]);

        $stmt = $pdo->prepare(
            "SELECT id, at, type, actor_login, actor_name, amount, tier, message, data
               FROM channel_events WHERE user_id = ? AND type IN ({$marks})
           ORDER BY at DESC, id DESC
              LIMIT " . self::PER_PAGE . ' OFFSET ' . (max(1, $page) - 1) * self::PER_PAGE
        );
        $stmt->execute([$userId, ...$types]);

        $rows = array_map(static function (array $row): array {
            $row['data'] = json_decode((string) $row['data'], true) ?: [];

            return $row;
        }, $stmt->fetchAll());

        return ['rows' => $rows, 'total' => (int) $count->fetchColumn()];
    }

    /**
     * How many events of each filter in the last 30 days.
     *
     * @return array<string,int> by filter
     */
    public static function counts(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT type, count(*) AS n FROM channel_events
              WHERE user_id = ? AND at > now() - interval '30 days' GROUP BY type"
        );
        $stmt->execute([$userId]);
        $byType = array_column($stmt->fetchAll(), 'n', 'type');

        $counts = [];

        foreach (self::FILTERS as $filter => $types) {
            $counts[$filter] = array_sum(array_map(static fn (string $t): int => (int) ($byType[$t] ?? 0), $types));
        }

        return $counts;
    }

    /**
     * Whether the bot may see this channel's follows: the streamer's
     * Twitch connection allows it, or the bot is a moderator there with
     * that permission.
     */
    public static function followsVisible(int $userId): bool
    {
        if (TwitchUser::hasScope($userId, TwitchUser::FOLLOWERS_SCOPE)) {
            return true;
        }

        $channel = ChatBot::channel($userId);
        $scopes  = explode(' ', (string) (ChatBot::account()['scopes'] ?? ''));

        return $channel !== null && $channel['access'] === 'moderator' && in_array(TwitchUser::FOLLOWERS_SCOPE, $scopes, true);
    }

    /** Whether the administrators turned unfollow tracking on at all. */
    public static function unfollowsOn(): bool
    {
        return Settings::get(self::SETTING, '0') === '1';
    }

    public static function setUnfollowsOn(bool $on, int $adminId): void
    {
        Settings::set(self::SETTING, $on ? '1' : '0', $adminId);
        ChatBot::notify();
    }

    /** Whether this streamer has the Unfollows page (and its daily check). */
    public static function unfollowsFor(int $userId): bool
    {
        if (!self::unfollowsOn()) {
            return false;
        }

        $stmt = Database::connection()->prepare('SELECT is_enabled FROM follower_tracking WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * An administrator switches the daily check on or off for a channel.
     * Switched on again, it starts over: the next look only keeps the
     * list, so the time it was off does not show as unfollows.
     */
    public static function setUnfollowsFor(int $userId, bool $on): void
    {
        $pdo = Database::connection();

        $pdo->prepare(
            'INSERT INTO follower_tracking (user_id, is_enabled) VALUES (?, ?)
             ON CONFLICT (user_id) DO UPDATE SET is_enabled = EXCLUDED.is_enabled'
        )->execute([$userId, $on ? 'true' : 'false']);

        if ($on) {
            $pdo->prepare('UPDATE follower_tracking SET follower_count = NULL, checked_at = NULL, last_error = NULL WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM channel_followers WHERE user_id = ?')->execute([$userId]);
        }

        ChatBot::log($userId, 'info', $on ? 'Unfollow tracking switched on by an admin' : 'Unfollow tracking switched off by an admin');
    }

    /** @return array{checked_at:?string, follower_count:?int, last_error:?string}|null the daily check's state */
    public static function tracking(int $userId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT checked_at, follower_count, last_error FROM follower_tracking WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<int, bool> user id => tracking on, for the admin's channel list */
    public static function trackingByUser(): array
    {
        $rows = Database::connection()->query('SELECT user_id, is_enabled FROM follower_tracking')->fetchAll();

        return array_map('boolval', array_column($rows, 'is_enabled', 'user_id'));
    }

    /** One event as a short line for the list: the tier, months, amount, recipient… */
    public static function details(array $event): string
    {
        $parts = [];
        $tier  = $event['tier'] === 'prime' ? 'Prime' : ($event['tier'] !== null ? sprintf(__('ui.channel_event.tier'), $event['tier']) : null);

        switch ($event['type']) {
            case 'sub':
            case 'prime_upgrade':
                $parts[] = $tier;
                break;
            case 'resub':
                $parts[] = $tier;
                $parts[] = $event['amount'] ? sprintf(__('ui.channel_event.months'), (int) $event['amount']) : null;
                $parts[] = !empty($event['data']['streak']) ? sprintf(__('ui.channel_event.streak'), (int) $event['data']['streak']) : null;
                break;
            case 'gift_sub':
                $parts[] = $tier;
                $parts[] = !empty($event['data']['recipient']) ? sprintf(__('ui.channel_event.to'), $event['data']['recipient']) : null;
                break;
            case 'community_gift':
                $parts[] = sprintf(__('ui.channel_event.gifts'), (int) $event['amount']);
                $parts[] = $tier;
                break;
            case 'gift_upgrade':
                $parts[] = !empty($event['data']['gifter']) ? sprintf(__('ui.channel_event.from'), $event['data']['gifter']) : null;
                break;
            case 'cheer':
                $parts[] = sprintf(__('ui.channel_event.bits'), (int) $event['amount']);
                break;
            case 'bits_badge':
                $parts[] = sprintf(__('ui.channel_event.badge'), (int) $event['amount']);
                break;
            case 'raid':
                $parts[] = sprintf(__('ui.channel_event.viewers'), (int) $event['amount']);
                break;
            case 'watch_streak':
                $parts[] = sprintf(__('ui.channel_event.streams'), (int) $event['amount']);
                break;
            case 'charity':
                $parts[] = trim((int) $event['amount'] . ' ' . ($event['data']['currency'] ?? '')) . (!empty($event['data']['charity']) ? ' · ' . $event['data']['charity'] : '');
                break;
            case 'unfollow':
                $parts[] = !empty($event['data']['followed_at']) ? sprintf(__('ui.channel_event.followed_since'), fmt_date((string) $event['data']['followed_at'])) : null;
                break;
        }

        return implode(' · ', array_filter($parts, static fn (?string $p): bool => $p !== null && $p !== ''));
    }
}
