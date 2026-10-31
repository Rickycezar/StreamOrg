<?php
declare(strict_types=1);

/**
 * Badge-gated giveaways: a giveaway can be limited to viewers showing one
 * of the campaign badges of the streamer's Twitch channel (badges the
 * channel created for an event). The badges come from Twitch's list of
 * the channel's chat badges; the chosen ones are kept on the giveaway
 * with their name and icon, the icon copied as a data: URI, since the
 * battle draw (Games) runs under a policy that cannot load Twitch's
 * images. The battle draw receives them and lets only their holders in.
 */
final class GiveawayBadges
{
    public const MAX = 20;

    private const ICON_MAX = 30000;

    private const ICON_HOST = 'static-cdn.jtvnw.net';

    /**
     * How a channel's badges are read and an icon is downloaded (tests
     * replace them); Twitch and HTTP when null.
     *
     * @var ?Closure(string): array
     */
    public static ?Closure $fetch = null;

    /** @var ?Closure(string): ?string */
    public static ?Closure $download = null;

    /**
     * The campaign badges of the user's Twitch channel, by name.
     *
     * @return list<array{id:string, title:string, image:string}> image: Twitch's icon URL
     * @throws UserError when the channel is not connected or Twitch does not answer
     */
    public static function campaign(int $userId): array
    {
        $channel = (string) (TwitchUser::connection($userId)['twitch_user_id'] ?? '');

        if (self::$fetch === null && !Twitch::isConfigured()) {
            throw new UserError(__('ui.message.twitch_unconfigured'));
        }

        if ($channel === '') {
            throw new UserError(__('ui.message.badges_no_channel'));
        }

        $sets = self::$fetch !== null ? (self::$fetch)($channel) : self::fromTwitch($channel);
        $list = [];

        foreach ($sets as $set) {
            $id      = (string) ($set['set_id'] ?? '');
            $version = $set['versions'][0] ?? null;

            if (!str_starts_with($id, 'campaign-') || !is_array($version)) {
                continue;
            }

            $list[] = [
                'id'    => $id,
                'title' => mb_substr(trim((string) ($version['title'] ?? '')) ?: $id, 0, 60),
                'image' => (string) ($version['image_url_2x'] ?? $version['image_url_1x'] ?? ''),
            ];
        }

        usort($list, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $list;
    }

    /**
     * The badges to keep on a giveaway: the ids chosen, checked against
     * the channel's campaign badges, each with its name and icon. Badges
     * already kept are reused as they are.
     *
     * @param list<string> $ids
     * @param list<array> $kept the giveaway's current badges
     * @return list<array{id:string, title:string, image:string}> image: a data: URI, or '' when it could not be copied
     * @throws UserError when a badge is not the channel's or Twitch does not answer
     */
    public static function choose(int $userId, array $ids, array $kept = []): array
    {
        $ids  = array_slice(array_values(array_unique(array_filter(array_map('strval', $ids)))), 0, self::MAX);
        $kept = array_column($kept, null, 'id');

        if ($ids === []) {
            return [];
        }

        $known  = array_diff($ids, array_keys($kept)) === [] ? [] : array_column(self::campaign($userId), null, 'id');
        $chosen = [];

        foreach ($ids as $id) {
            if (isset($kept[$id])) {
                $chosen[] = $kept[$id];
                continue;
            }

            if (!isset($known[$id])) {
                throw new UserError(__('ui.message.badges_unknown'));
            }

            $chosen[] = ['id' => $id, 'title' => $known[$id]['title'], 'image' => self::icon($known[$id]['image'])];
        }

        return $chosen;
    }

    /** @return list<array{id:string, title:string, image:string}> a giveaway's badges, when it is badge-gated */
    public static function of(array $giveaway): array
    {
        if (empty($giveaway['badge_gate'])) {
            return [];
        }

        $badges = is_array($giveaway['badges'] ?? null) ? $giveaway['badges'] : json_decode((string) ($giveaway['badges'] ?? '[]'), true);

        return array_values(array_filter(is_array($badges) ? $badges : [], static fn ($b): bool => is_array($b) && ($b['id'] ?? '') !== ''));
    }

    /**
     * A giveaway's badges as the battle draw takes them (its config's
     * "badges"): only the badge's holders may enter.
     *
     * @return list<array{id:string, title:string, image?:string}>
     */
    public static function forGame(array $giveaway): array
    {
        return array_map(static fn (array $b): array => array_filter(
            ['id' => (string) $b['id'], 'title' => mb_substr((string) ($b['title'] ?? ''), 0, 60), 'image' => (string) ($b['image'] ?? '')],
            static fn (string $v): bool => $v !== ''
        ), self::of($giveaway));
    }

    /** @return list<array> the channel's badge sets, from Twitch */
    private static function fromTwitch(string $channel): array
    {
        $response = Twitch::app('GET', 'chat/badges', ['broadcaster_id' => $channel]);
        $data     = json_decode((string) ($response['body'] ?? ''), true)['data'] ?? null;

        if ((int) ($response['status'] ?? 0) !== 200 || !is_array($data)) {
            ErrorLog::note('twitch channel badges: ' . Twitch::describe($response, 'chat/badges'));
            throw new UserError(__('ui.message.provider_error'));
        }

        return $data;
    }

    /** A badge icon from Twitch as a data: URI, or '' when it cannot be copied. */
    private static function icon(string $url): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== self::ICON_HOST) {
            return '';
        }

        $bytes = self::$download !== null ? (self::$download)($url) : self::get($url);

        if ($bytes === null || $bytes === '' || strlen($bytes) > self::ICON_MAX) {
            return '';
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($type, ['image/png', 'image/gif', 'image/webp'], true) ? 'data:' . $type . ';base64,' . base64_encode($bytes) : '';
    }

    private static function get(string $url): ?string
    {
        $response = Http::get($url, [], 10, self::ICON_MAX + 1);

        return $response['status'] === 200 ? $response['body'] : null;
    }
}
