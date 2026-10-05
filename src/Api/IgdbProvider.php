<?php
declare(strict_types=1);

/**
 * IGDB, via Twitch's OAuth. Needs a client id and secret from the Twitch
 * developer console. Tokens are cached in the session for their lifetime.
 */
final class IgdbProvider extends Provider
{
    private const TOKEN_KEY = 'igdb_token';

    /** IGDB's external_game_source for Steam. */
    private const STEAM_SOURCE = 1;

    public function code(): string
    {
        return 'igdb';
    }

    public function requiredCredentials(): array
    {
        return ['client_id', 'client_secret'];
    }

    public function search(string $term): array
    {
        $term = trim($term);

        if ($term === '' || !$this->isConfigured()) {
            return [];
        }

        $query = 'search "' . addslashes($term) . '"; '
               . 'fields name, first_release_date, cover.image_id; limit 20;';

        $games = $this->query($query);

        $results = [];

        foreach ($games as $game) {
            if (!isset($game['id'], $game['name'])) {
                continue;
            }

            $results[] = [
                'ref'   => (string) $game['id'],
                'title' => (string) $game['name'],
                'year'  => isset($game['first_release_date'])
                    ? date('Y', (int) $game['first_release_date'])
                    : null,
                'image' => isset($game['cover']['image_id'])
                    ? 'https://images.igdb.com/igdb/image/upload/t_cover_big/' . $game['cover']['image_id'] . '.jpg'
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

        $query = 'fields name, summary, first_release_date, cover.image_id, url, '
               . 'genres.name, involved_companies.company.name, '
               . 'involved_companies.publisher, involved_companies.developer; '
               . 'where id = ' . (int) $ref . '; limit 1;';

        $games = $this->query($query);
        $game  = $games[0] ?? null;

        if (!is_array($game) || !isset($game['name'])) {
            return null;
        }

        $publishers = [];
        $developers = [];

        foreach ($game['involved_companies'] ?? [] as $involved) {
            $name = $involved['company']['name'] ?? null;

            if (!is_string($name)) {
                continue;
            }

            if (!empty($involved['publisher'])) {
                $publishers[] = $name;
            }

            if (!empty($involved['developer'])) {
                $developers[] = $name;
            }
        }

        return [
            'ref'          => (string) ($game['id'] ?? $ref),
            'title'        => (string) $game['name'],
            'description'  => isset($game['summary']) ? trim((string) $game['summary']) : null,
            'release_date' => isset($game['first_release_date'])
                ? date('Y-m-d', (int) $game['first_release_date'])
                : null,
            'publishers'   => array_values(array_unique($publishers)),
            'developers'   => array_values(array_unique($developers)),
            'genres'       => array_column($game['genres'] ?? [], 'name'),
            'cover_url'    => isset($game['cover']['image_id'])
                ? 'https://images.igdb.com/igdb/image/upload/t_cover_big/' . $game['cover']['image_id'] . '.jpg'
                : null,
            'images'       => isset($game['cover']['image_id'])
                ? ['portrait' => 'https://images.igdb.com/igdb/image/upload/t_cover_big_2x/' . $game['cover']['image_id'] . '.jpg']
                : [],
            'store_url'    => isset($game['url']) ? (string) $game['url'] : null,
        ];
    }

    /**
     * IGDB as configured, or else through the Twitch app's credentials
     * (IGDB accepts any Twitch app), so adding a game from Twitch can use
     * it even when IGDB was never switched on as a provider.
     */
    public static function forTwitch(): ?self
    {
        $configured = Providers::get('igdb');

        if ($configured instanceof self && $configured->isAvailable()) {
            return $configured;
        }

        if (!Twitch::isConfigured()) {
            return null;
        }

        return new self(['client_id' => Twitch::clientId(), 'client_secret' => Twitch::clientSecret(), 'is_enabled' => true]);
    }

    /** The Steam app id IGDB records for a game, if it is on Steam. */
    public function steamAppId(string $ref): ?string
    {
        if (!ctype_digit($ref) || !$this->isConfigured()) {
            return null;
        }

        $games = $this->query('fields external_games.uid, external_games.external_game_source; where id = ' . (int) $ref . '; limit 1;');

        foreach ($games[0]['external_games'] ?? [] as $external) {
            if ((int) ($external['external_game_source'] ?? 0) === self::STEAM_SOURCE && ctype_digit((string) ($external['uid'] ?? ''))) {
                return (string) $external['uid'];
            }
        }

        return null;
    }
    /** @return list<array<string,mixed>> */
    private function query(string $apicalypse): array
    {
        $token = $this->token();

        if ($token === null) {
            return [];
        }

        $response = Http::post('https://api.igdb.com/v4/games', $apicalypse, [
            'Client-ID'     => $this->setting('client_id'),
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ]);

        $data = Http::json($response);

        return is_array($data) ? $data : [];
    }

    private function token(): ?string
    {
        $cached = $_SESSION[self::TOKEN_KEY] ?? null;

        if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60) {
            return (string) $cached['token'];
        }

        $response = Http::post('https://id.twitch.tv/oauth2/token?' . http_build_query([
            'client_id'     => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
            'grant_type'    => 'client_credentials',
        ]), '');

        $data = Http::json($response);

        if ($data === null || !isset($data['access_token'])) {
            return null;
        }

        $_SESSION[self::TOKEN_KEY] = [
            'token'   => (string) $data['access_token'],
            'expires' => time() + (int) ($data['expires_in'] ?? 3600),
        ];

        return (string) $data['access_token'];
    }
}
