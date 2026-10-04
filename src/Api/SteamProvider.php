<?php
declare(strict_types=1);

/**
 * Steam's public storefront endpoints. No API key exists for these, which
 * is why this provider is available by default.
 */
final class SteamProvider extends Provider
{
    public function code(): string
    {
        return 'steam';
    }

    public function requiredCredentials(): array
    {
        return [];
    }

    public function search(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $response = Http::get('https://steamcommunity.com/actions/SearchApps/' . rawurlencode($term));
        $data     = Http::json($response);

        if ($data === null) {
            return [];
        }

        $results = [];

        foreach (array_slice($data, 0, 20) as $item) {
            if (!isset($item['appid'], $item['name'])) {
                continue;
            }

            $results[] = [
                'ref'   => (string) $item['appid'],
                'title' => (string) $item['name'],
                'year'  => null,
                'image' => isset($item['logo']) ? (string) $item['logo'] : null,
            ];
        }

        return $results;
    }

    public function fetch(string $ref): ?array
    {
        $response = Http::get(
            'https://store.steampowered.com/api/appdetails?appids=' . rawurlencode($ref) . '&l=english'
        );
        $payload = Http::json($response);

        if ($payload === null) {
            return null;
        }

        $entry = $payload[$ref] ?? reset($payload);

        if (!is_array($entry) || empty($entry['success']) || !isset($entry['data'])) {
            return null;
        }

        $data = $entry['data'];

        $release = self::parseRelease($data['release_date']['date'] ?? null);

        $genres = [];
        foreach ($data['genres'] ?? [] as $genre) {
            if (isset($genre['description'])) {
                $genres[] = (string) $genre['description'];
            }
        }

        $appId = (string) ($data['steam_appid'] ?? $ref);

        return [
            'ref'          => $appId,
            'title'        => (string) ($data['name'] ?? ''),
            'description'  => isset($data['short_description'])
                ? trim(strip_tags((string) $data['short_description']))
                : null,
            'release_date'      => $release['date'],
            'release_precision' => $release['precision'],
            'release_raw'       => $release['raw'],
            'publishers'   => array_values(array_filter(array_map('strval', $data['publishers'] ?? []))),
            'developers'   => array_values(array_filter(array_map('strval', $data['developers'] ?? []))),
            'genres'       => $genres,
            'cover_url'    => isset($data['header_image']) ? (string) $data['header_image'] : null,
            'images'       => self::images($appId, $data),
            'store_url'    => 'https://store.steampowered.com/app/' . $appId,
        ];
    }

    /**
     * Artwork for each place the app shows a game.
     *
     * The store fields come with appdetails. The library art (box art, hero,
     * logo) is not listed there but lives at fixed paths for nearly every
     * app; a title without it simply fails that download. Those paths carry
     * no version, so the store header's ?t= stamp is attached: Steam bumps
     * it when a page's assets change, which is what tells a later refresh
     * to download again.
     *
     * @return array<string,string> kind => URL
     */
    private static function images(string $appId, array $data): array
    {
        $images = [];

        if (!empty($data['header_image'])) {
            $images['header'] = (string) $data['header_image'];
        }

        if (!empty($data['capsule_image'])) {
            $images['capsule'] = (string) $data['capsule_image'];
        }

        parse_str((string) parse_url((string) ($data['header_image'] ?? ''), PHP_URL_QUERY), $query);
        $stamp = isset($query['t']) ? '?t=' . rawurlencode((string) $query['t']) : '';
        $base  = 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/' . rawurlencode($appId) . '/';

        $images['portrait'] = $base . 'library_600x900_2x.jpg' . $stamp;
        $images['hero']     = $base . 'library_hero.jpg' . $stamp;
        $images['logo']     = $base . 'logo.png' . $stamp;

        return $images;
    }
}
