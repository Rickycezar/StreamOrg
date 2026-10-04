<?php
declare(strict_types=1);

/** RAWG's games database. Needs a free API key. */
final class RawgProvider extends Provider
{
    public function code(): string
    {
        return 'rawg';
    }

    public function requiredCredentials(): array
    {
        return ['api_key'];
    }

    public function search(string $term): array
    {
        $term = trim($term);

        if ($term === '' || !$this->isConfigured()) {
            return [];
        }

        $url = 'https://api.rawg.io/api/games?' . http_build_query([
            'key'       => $this->setting('api_key'),
            'search'    => $term,
            'page_size' => 20,
        ]);

        $data = Http::json(Http::get($url));

        if ($data === null) {
            return [];
        }

        $results = [];

        foreach ($data['results'] ?? [] as $item) {
            if (!isset($item['id'], $item['name'])) {
                continue;
            }

            $results[] = [
                'ref'   => (string) $item['id'],
                'title' => (string) $item['name'],
                'year'  => isset($item['released']) ? substr((string) $item['released'], 0, 4) : null,
                'image' => isset($item['background_image']) ? (string) $item['background_image'] : null,
            ];
        }

        return $results;
    }

    public function fetch(string $ref): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $url = 'https://api.rawg.io/api/games/' . rawurlencode($ref)
             . '?key=' . rawurlencode($this->setting('api_key'));

        $data = Http::json(Http::get($url));

        if ($data === null || !isset($data['name'])) {
            return null;
        }

        return [
            'ref'          => (string) ($data['id'] ?? $ref),
            'title'        => (string) $data['name'],
            'description'  => isset($data['description_raw'])
                ? trim((string) $data['description_raw'])
                : null,
            'release_date' => self::normaliseDate($data['released'] ?? null),
            'publishers'   => array_column($data['publishers'] ?? [], 'name'),
            'developers'   => array_column($data['developers'] ?? [], 'name'),
            'genres'       => array_column($data['genres'] ?? [], 'name'),
            'cover_url'    => isset($data['background_image']) ? (string) $data['background_image'] : null,
            'images'       => isset($data['background_image']) ? ['header' => (string) $data['background_image']] : [],
            'store_url'    => isset($data['website']) && $data['website'] !== '' ? (string) $data['website'] : null,
        ];
    }
}
