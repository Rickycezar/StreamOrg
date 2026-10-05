<?php
declare(strict_types=1);

/**
 * Twitch Helix lookups for streamer profiles.
 *
 * Deliberately not a Provider subclass: that contract is about game
 * catalogues and returns games. This returns people.
 *
 * Authenticates with the client-credentials flow, which is enough for
 * public profile data. Follower counts are not fetched — since 2023 that
 * endpoint needs a user token with moderator:read:followers, which a
 * server-side app token cannot obtain.
 */
final class Twitch
{
    private const TOKEN_KEY = 'twitch_app_token';

    /** @var array{enabled:bool, client_id:string, client_secret:string}|null */
    private static ?array $settings = null;

    /** Why the last call failed, for the admin screen. */
    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Credentials live in api_settings alongside the other integrations,
     * encrypted at rest. Read once per request.
     *
     * @return array{enabled:bool, client_id:string, client_secret:string}
     */
    private static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $row = Database::connection()
            ->query("SELECT is_enabled, client_id, client_secret FROM api_settings WHERE provider = 'twitch'")
            ->fetch();

        return self::$settings = [
            'enabled'       => (bool) ($row['is_enabled'] ?? false),
            'client_id'     => trim((string) Crypto::decrypt($row['client_id'] ?? null)),
            'client_secret' => trim((string) Crypto::decrypt($row['client_secret'] ?? null)),
        ];
    }

    /** Drops the cached row so a settings save takes effect immediately. */
    public static function forget(): void
    {
        self::$settings = null;
        unset($_SESSION[self::TOKEN_KEY]);
    }

    /** Enabled, and holding both halves of the credential pair. */
    public static function isConfigured(): bool
    {
        $s = self::settings();

        return $s['enabled'] && $s['client_id'] !== '' && $s['client_secret'] !== '';
    }

    public static function clientId(): string
    {
        return self::settings()['client_id'];
    }

    public static function clientSecret(): string
    {
        return self::settings()['client_secret'];
    }

    /**
     * Channel search by name. Returns partial profiles — enough to pick
     * from, not enough to store.
     *
     * @return list<array{ref:string, login:string, name:string, avatar:?string, live:bool, game:?string}>
     */
    public static function search(string $term): array
    {
        $term = trim($term);

        if ($term === '' || !self::isConfigured()) {
            return [];
        }

        $data = self::get('search/channels', ['query' => $term, 'first' => 20]);

        $results = [];

        foreach ($data['data'] ?? [] as $row) {
            if (!isset($row['id'], $row['broadcaster_login'])) {
                continue;
            }

            $results[] = [
                'ref'    => (string) $row['id'],
                'login'  => (string) $row['broadcaster_login'],
                'name'   => (string) ($row['display_name'] ?: $row['broadcaster_login']),
                'avatar' => isset($row['thumbnail_url']) ? (string) $row['thumbnail_url'] : null,
                'live'   => !empty($row['is_live']),
                'game'   => isset($row['game_name']) && $row['game_name'] !== '' ? (string) $row['game_name'] : null,
            ];
        }

        return $results;
    }

    /**
     * Full profile by Twitch user id, or by login when $byLogin is true.
     *
     * @return array{ref:string, login:string, name:string, avatar:?string,
     *               description:?string, broadcaster_type:?string, url:string}|null
     */
    public static function user(string $ref, bool $byLogin = false): ?array
    {
        if (trim($ref) === '' || !self::isConfigured()) {
            return null;
        }

        $data = self::get('users', [$byLogin ? 'login' : 'id' => $ref]);
        $row  = $data['data'][0] ?? null;

        if (!is_array($row) || !isset($row['id'])) {
            return null;
        }

        $login = (string) $row['login'];

        return [
            'ref'              => (string) $row['id'],
            'login'            => $login,
            'name'             => (string) ($row['display_name'] ?: $login),
            'avatar'           => isset($row['profile_image_url']) ? (string) $row['profile_image_url'] : null,
            'description'      => isset($row['description']) && $row['description'] !== ''
                ? (string) $row['description'] : null,
            'broadcaster_type' => isset($row['broadcaster_type']) && $row['broadcaster_type'] !== ''
                ? (string) $row['broadcaster_type'] : null,
            'url'              => 'https://twitch.tv/' . $login,
        ];
    }

    /**
     * Live check against the credentials.
     *
     * @return array{ok:bool, note:string}
     */
    public static function test(): array
    {
        $s = self::settings();

        if ($s['client_id'] === '' || $s['client_secret'] === '') {
            return ['ok' => false, 'note' => 'client_id or client_secret is empty. If you just saved them, check security.app_key has not changed.'];
        }

        if (!$s['enabled']) {
            return ['ok' => false, 'note' => 'Credentials are stored but the integration is switched off — tick Enabled and save.'];
        }

        self::forget();

        if (self::token() === null) {
            return ['ok' => false, 'note' => self::$lastError ?? 'Twitch refused the credentials.'];
        }

        $probe = self::get('users', ['login' => 'twitch']);

        if (($probe['data'][0]['id'] ?? null) === null) {
            return ['ok' => false, 'note' => self::$lastError ?? 'Authenticated, but the API call failed.'];
        }

        return ['ok' => true, 'note' => 'Authenticated and reachable.'];
    }

    /** Categories commonly used for content that is not a single game. */
    public const COMMON_CATEGORIES = ['Just Chatting', 'Special Events', 'Games + Demos', 'Talk Shows & Podcasts', 'Marbles On Stream', 'IRL'];

    /**
     * Twitch categories (games and non-game ones) for a search term; with
     * no term, the common non-game categories.
     *
     * @return list<array{id:string, name:string, cover:?string}>
     */
    public static function searchCategories(string $term): array
    {
        if (!self::isConfigured()) {
            return [];
        }

        $term = trim($term);
        $data = $term === ''
            ? self::get('games', ['name' => self::COMMON_CATEGORIES])
            : self::get('search/categories', ['query' => mb_substr($term, 0, 80), 'first' => 20]);

        return array_values(array_map(static fn (array $row): array => [
            'id'    => (string) $row['id'],
            'name'  => (string) $row['name'],
            'cover' => isset($row['box_art_url'])
                ? str_replace(['{width}', '{height}'], ['52', '72'], (string) $row['box_art_url'])
                : null,
        ], array_filter((array) ($data['data'] ?? []), static fn ($row): bool => is_array($row) && isset($row['id'], $row['name']))));
    }

    /** @return array{id:string, name:string}|null a category by its Twitch id */
    public static function category(string $id): ?array
    {
        if (!ctype_digit($id) || !self::isConfigured()) {
            return null;
        }

        $row = self::get('games', ['id' => $id])['data'][0] ?? null;

        return is_array($row) && isset($row['id']) ? ['id' => (string) $row['id'], 'name' => (string) $row['name']] : null;
    }

    /**
     * One Helix call with the app token, for endpoints that need no user
     * (EventSub subscriptions). Returns the raw response.
     *
     * @return array{status:int, body:string, error:?string}
     */
    public static function app(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $token = self::token();

        if ($token === null) {
            return ['status' => 0, 'body' => '', 'error' => self::$lastError ?? 'No app token.'];
        }

        $url     = 'https://api.twitch.tv/helix/' . $path . ($query === [] ? '' : '?' . self::query($query));
        $headers = [
            'Client-Id'     => self::clientId(),
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ];

        $response = match ($method) {
            'GET'    => Http::get($url, $headers),
            'DELETE' => Http::delete($url, $headers),
            default  => Http::post($url, (string) json_encode($body), $headers + ['Content-Type' => 'application/json']),
        };

        if ($response['status'] === 401) {
            unset($_SESSION[self::TOKEN_KEY]);
        }

        return $response;
    }

    /** @param array<string, string|int> $query */
    private static function get(string $path, array $query): array
    {
        $token = self::token();

        if ($token === null) {
            return [];
        }

        $response = Http::get(
            'https://api.twitch.tv/helix/' . $path . '?' . self::query($query),
            [
                'Client-Id'     => self::clientId(),
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ]
        );

        if ($response['status'] === 401) {
            unset($_SESSION[self::TOKEN_KEY]);
        }

        $data = Http::json($response);

        if ($data === null) {
            self::$lastError = self::describe($response, 'helix/' . $path);
            return [];
        }

        return $data;
    }

    /**
     * Turns a failed response into something actionable, using Twitch's own
     * error text where there is one.
     *
     * @param array{status:int, body:string, error:?string} $response
     */
    public static function describe(array $response, string $where): string
    {
        if ($response['error'] !== null) {
            return "Could not reach Twitch ({$where}): {$response['error']}";
        }

        $body    = json_decode($response['body'], true);
        $message = is_array($body) ? (string) ($body['message'] ?? '') : '';

        if ($message !== '') {
            return "Twitch returned {$response['status']} on {$where}: {$message}";
        }

        return "Twitch returned {$response['status']} on {$where}.";
    }

    /** Query string with list values repeated (name=a&name=b), as Helix expects. */
    private static function query(array $query): string
    {
        $parts = [];

        foreach ($query as $key => $value) {
            foreach ((array) $value as $item) {
                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
            }
        }

        return implode('&', $parts);
    }

    /** App access token, cached in the session for its stated lifetime. */
    private static function token(): ?string
    {
        $cached = $_SESSION[self::TOKEN_KEY] ?? null;

        if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60) {
            return (string) $cached['token'];
        }

        $response = Http::post('https://id.twitch.tv/oauth2/token', http_build_query([
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'grant_type'    => 'client_credentials',
        ]), ['Content-Type' => 'application/x-www-form-urlencoded']);

        $data = Http::json($response);

        if ($data === null || !isset($data['access_token'])) {
            self::$lastError = self::describe($response, 'oauth2/token');
            return null;
        }

        $_SESSION[self::TOKEN_KEY] = [
            'token'   => (string) $data['access_token'],
            'expires' => time() + (int) ($data['expires_in'] ?? 3600),
        ];

        return (string) $data['access_token'];
    }
}
