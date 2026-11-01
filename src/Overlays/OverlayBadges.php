<?php
declare(strict_types=1);

/**
 * The images of Twitch's chat badges for the chat overlay: Twitch's global
 * badges (VIP, moderator, broadcaster, events…) and the channel's own
 * (subscriber months, Bits, campaigns), by "set_id/version" as chat sends
 * them. Each list is kept for CACHE_HOURS (twitch_badge_cache); when Twitch
 * does not answer, the last copy is used.
 */
final class OverlayBadges
{
    private const CACHE_HOURS = 6;

    /**
     * How a badge list is read from Twitch (tests replace it); the app
     * token when null. Gets the Helix path and query, returns its "data".
     *
     * @var ?Closure(string, array): ?array
     */
    public static ?Closure $fetch = null;

    /**
     * Every badge a chatter of the channel can show, the channel's own
     * replacing the global ones of the same id.
     *
     * @return array<string, string> "set_id/version" => image URL
     */
    public static function map(?string $channelId): array
    {
        $map = self::scope('global', 'chat/badges/global', []);

        if ($channelId !== null && ctype_digit($channelId)) {
            $map = array_replace($map, self::scope($channelId, 'chat/badges', ['broadcaster_id' => $channelId]));
        }

        return $map;
    }

    /** @return array<string, string> one list, from the cache or from Twitch */
    private static function scope(string $scope, string $path, array $query): array
    {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare('SELECT data, fetched_at > now() - make_interval(hours => ?) AS fresh FROM twitch_badge_cache WHERE scope = ?');
        $stmt->execute([self::CACHE_HOURS, $scope]);
        $row    = $stmt->fetch();
        $cached = $row !== false ? (json_decode((string) $row['data'], true) ?: []) : null;

        if ($row !== false && $row['fresh']) {
            return $cached;
        }

        $sets = self::$fetch !== null ? (self::$fetch)($path, $query) : self::fromTwitch($path, $query);

        if ($sets === null) {
            return $cached ?? [];
        }

        $map = [];

        foreach ($sets as $set) {
            foreach ((array) ($set['versions'] ?? []) as $version) {
                $url = (string) ($version['image_url_2x'] ?? $version['image_url_1x'] ?? '');

                if (isset($set['set_id'], $version['id']) && str_starts_with($url, 'https://')) {
                    $map[$set['set_id'] . '/' . $version['id']] = $url;
                }
            }
        }

        $pdo->prepare(
            'INSERT INTO twitch_badge_cache (scope, data) VALUES (?, CAST(? AS jsonb))
             ON CONFLICT (scope) DO UPDATE SET data = excluded.data, fetched_at = now()'
        )->execute([$scope, json_encode((object) $map, JSON_UNESCAPED_SLASHES)]);

        return $map;
    }

    /** @return ?list<array> a badge list from Twitch, or null when it does not answer */
    private static function fromTwitch(string $path, array $query): ?array
    {
        if (!Twitch::isConfigured()) {
            return null;
        }

        $response = Twitch::app('GET', $path, $query);
        $data     = json_decode((string) ($response['body'] ?? ''), true)['data'] ?? null;

        if ((int) ($response['status'] ?? 0) !== 200 || !is_array($data)) {
            ErrorLog::note('twitch badges for overlays: ' . Twitch::describe($response, $path));
            return null;
        }

        return $data;
    }
}
