<?php
declare(strict_types=1);

/**
 * OMDb, the IMDb-backed API. Its game coverage is thin compared with the
 * games-specific providers, but it is the practical way to reach IMDb data.
 * Needs a free API key from omdbapi.com.
 */
final class OmdbProvider extends Provider
{
    public function code(): string
    {
        return 'omdb';
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

        $url = 'https://www.omdbapi.com/?' . http_build_query([
            'apikey' => $this->setting('api_key'),
            's'      => $term,
            'type'   => 'game',
        ]);

        $data = Http::json(Http::get($url));

        if ($data === null || ($data['Response'] ?? 'False') !== 'True') {
            return [];
        }

        $results = [];

        foreach ($data['Search'] ?? [] as $item) {
            if (!isset($item['imdbID'], $item['Title'])) {
                continue;
            }

            $results[] = [
                'ref'   => (string) $item['imdbID'],
                'title' => (string) $item['Title'],
                'year'  => isset($item['Year']) ? (string) $item['Year'] : null,
                'image' => isset($item['Poster']) && $item['Poster'] !== 'N/A'
                    ? (string) $item['Poster']
                    : null,
            ];
        }

        return $results;
    }

    public function fetch(string $ref): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $url = 'https://www.omdbapi.com/?' . http_build_query([
            'apikey' => $this->setting('api_key'),
            'i'      => $ref,
            'plot'   => 'short',
        ]);

        $data = Http::json(Http::get($url));

        if ($data === null || ($data['Response'] ?? 'False') !== 'True') {
            return null;
        }

        $split = static fn (?string $value): array => $value === null || $value === 'N/A'
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $value))));

        return [
            'ref'          => (string) ($data['imdbID'] ?? $ref),
            'title'        => (string) ($data['Title'] ?? ''),
            'description'  => ($data['Plot'] ?? 'N/A') !== 'N/A' ? (string) $data['Plot'] : null,
            'release_date' => self::normaliseDate(($data['Released'] ?? 'N/A') !== 'N/A' ? $data['Released'] : null),
            'publishers'   => $split($data['Production'] ?? null),
            'developers'   => $split($data['Director'] ?? null),
            'genres'       => $split($data['Genre'] ?? null),
            'cover_url'    => ($data['Poster'] ?? 'N/A') !== 'N/A' ? (string) $data['Poster'] : null,
            'images'       => ($data['Poster'] ?? 'N/A') !== 'N/A' ? ['portrait' => (string) $data['Poster']] : [],
            'store_url'    => isset($data['imdbID']) ? 'https://www.imdb.com/title/' . $data['imdbID'] . '/' : null,
        ];
    }
}
