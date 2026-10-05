<?php
declare(strict_types=1);

/**
 * A user's own Twitch authorisation, for changing their channel.
 *
 * Twitch.php works with an app token, which can read public data but never
 * modify a channel. Editing the title, category and tags needs a user
 * token with channel:manage:broadcast (and the channel schedule,
 * channel:manage:schedule), obtained through the authorization
 * code flow: the user is sent to Twitch, approves, and comes back to
 * /profile/twitch/callback with a code that is exchanged for tokens.
 *
 * Tokens are stored encrypted in twitch_connections and refreshed when
 * they expire. The client id and secret are the same pair an admin enters
 * for the streamer lookups.
 */
final class TwitchUser
{
    public const SCOPE = 'channel:manage:broadcast channel:manage:schedule';

    public const SCHEDULE_SCOPE = 'channel:manage:schedule';

    /** Twitch's limits for Modify Channel Information. */
    public const TITLE_MAX = 140;
    public const TAG_MAX   = 25;
    public const TAGS_MAX  = 10;

    private const STATE_KEY = 'streamorg_twitch_state';

    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Where Twitch sends the user back. Must be registered, character for
     * character, under OAuth Redirect URLs in the Twitch developer console,
     * so it is built from the address the app is actually reached at.
     */
    public static function redirectUri(): string
    {
        $requested = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $bareHost  = (string) preg_replace('/:\d+$/', '', $requested);

        if ($requested !== '' && request_is_https() && in_array($bareHost, app_hosts(), true)) {
            return 'https://' . $requested . url('/profile/twitch/callback');
        }

        $base = safe_url((string) Config::get('app.base_url', ''));

        if ($base !== null) {
            $parts = parse_url($base);

            return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
                . url('/profile/twitch/callback');
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return (request_is_https() ? 'https' : 'http') . '://' . $host . url('/profile/twitch/callback');
    }

    /**
     * Every redirect URL to register in the Twitch console: one per public
     * domain, plus the base URL's.
     *
     * @return list<string>
     */
    public static function redirectUris(): array
    {
        $uris = [];
        $base = safe_url((string) Config::get('app.base_url', ''));

        if ($base !== null) {
            $parts  = parse_url($base);
            $uris[] = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
                . url('/profile/twitch/callback');
        }

        foreach (array_keys((array) Config::get('app.domain_locales', [])) as $domain) {
            $uris[] = 'https://' . strtolower((string) $domain) . url('/profile/twitch/callback');
        }

        return array_values(array_unique($uris));
    }

    /** The Twitch consent page, with a one-time state to tie the reply to this session. */
    public static function authorizeUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION[self::STATE_KEY] = $state;

        return 'https://id.twitch.tv/oauth2/authorize?' . http_build_query([
            'client_id'     => Twitch::clientId(),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'state'         => $state,
            'force_verify'  => 'true',
        ]);
    }

    /**
     * Finishes the authorization: checks the state, exchanges the code and
     * stores who the tokens belong to.
     *
     * @return array{login:string}|null null on failure; see lastError()
     */
    public static function complete(int $userId, string $code, string $state): ?array
    {
        $expected = (string) ($_SESSION[self::STATE_KEY] ?? '');
        unset($_SESSION[self::STATE_KEY]);

        if ($expected === '' || !hash_equals($expected, $state)) {
            self::$lastError = 'state';
            return null;
        }

        $tokens = self::tokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => self::redirectUri(),
        ]);

        if ($tokens === null) {
            return null;
        }

        $who = Http::json(Http::get('https://id.twitch.tv/oauth2/validate', [
            'Authorization' => 'OAuth ' . $tokens['access_token'],
        ]));

        if ($who === null || empty($who['user_id']) || empty($who['login'])) {
            self::$lastError = 'validate';
            return null;
        }

        Database::connection()->prepare(
            'INSERT INTO twitch_connections
                 (user_id, twitch_user_id, twitch_login, access_token, refresh_token, expires_at, scopes)
             VALUES (?, ?, ?, ?, ?, now() + make_interval(secs => ?), ?)
             ON CONFLICT (user_id) DO UPDATE
                SET twitch_user_id = EXCLUDED.twitch_user_id, twitch_login = EXCLUDED.twitch_login,
                    access_token = EXCLUDED.access_token, refresh_token = EXCLUDED.refresh_token,
                    expires_at = EXCLUDED.expires_at, scopes = EXCLUDED.scopes, connected_at = now()'
        )->execute([
            $userId,
            (string) $who['user_id'],
            (string) $who['login'],
            Crypto::encrypt($tokens['access_token']),
            Crypto::encrypt($tokens['refresh_token']),
            $tokens['expires_in'],
            implode(' ', $tokens['scope']),
        ]);

        return ['login' => (string) $who['login']];
    }

    /**
     * A viewer's Twitch sign-in: exchanges the code, reads who it is, and
     * revokes the token at once — StreamOrg keeps no access to viewers'
     * accounts, only their identity.
     *
     * @return array{user_id:string, login:string}|null
     */
    public static function identify(string $code): ?array
    {
        $tokens = self::tokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => self::redirectUri(),
        ]);

        if ($tokens === null) {
            return null;
        }

        $who = Http::json(Http::get('https://id.twitch.tv/oauth2/validate', [
            'Authorization' => 'OAuth ' . $tokens['access_token'],
        ]));

        Http::post('https://id.twitch.tv/oauth2/revoke', http_build_query([
            'client_id' => Twitch::clientId(),
            'token'     => $tokens['access_token'],
        ]), ['Content-Type' => 'application/x-www-form-urlencoded']);

        if ($who === null || empty($who['user_id']) || empty($who['login'])) {
            return null;
        }

        return ['user_id' => (string) $who['user_id'], 'login' => (string) $who['login']];
    }

    /** @return array{twitch_user_id:string, twitch_login:string, connected_at:string}|null */
    public static function connection(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT twitch_user_id, twitch_login, connected_at FROM twitch_connections WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Whether the user's connection was approved with that scope (older ones predate the schedule). */
    public static function hasScope(int $userId, string $scope): bool
    {
        $stmt = Database::connection()->prepare('SELECT scopes FROM twitch_connections WHERE user_id = ?');
        $stmt->execute([$userId]);
        $scopes = $stmt->fetchColumn();

        return $scopes !== false && in_array($scope, explode(' ', (string) $scopes), true);
    }

    /**
     * One call to the channel schedule (Helix schedule/segment) for the
     * user's own channel: POST creates, PATCH updates, DELETE removes.
     *
     * @return array{status:int, body:string, error:?string}|null null when not connected
     */
    public static function scheduleSegment(int $userId, string $method, ?string $segmentId, ?array $body = null): ?array
    {
        $conn = self::connection($userId);

        if ($conn === null) {
            self::$lastError = 'not_connected';
            return null;
        }

        $query = ['broadcaster_id' => $conn['twitch_user_id']] + ($segmentId !== null ? ['id' => $segmentId] : []);

        return self::helixRaw($userId, $method, 'schedule/segment', $query, $body);
    }

    /** Revokes the token at Twitch (best effort) and forgets it here. */
    public static function disconnect(int $userId): void
    {
        $token = self::storedToken($userId);

        if ($token !== null) {
            Http::post('https://id.twitch.tv/oauth2/revoke', http_build_query([
                'client_id' => Twitch::clientId(),
                'token'     => $token,
            ]), ['Content-Type' => 'application/x-www-form-urlencoded']);
        }

        Database::connection()->prepare('DELETE FROM twitch_connections WHERE user_id = ?')->execute([$userId]);
    }

    /**
     * The channel as it is right now.
     *
     * @return array{title:string, game_id:string, game_name:string, tags:list<string>}|null
     */
    public static function channel(int $userId): ?array
    {
        $conn = self::connection($userId);

        if ($conn === null) {
            return null;
        }

        $data = self::helix($userId, 'GET', 'channels', ['broadcaster_id' => $conn['twitch_user_id']]);
        $row  = $data['data'][0] ?? null;

        if (!is_array($row)) {
            return null;
        }

        return [
            'title'     => (string) ($row['title'] ?? ''),
            'game_id'   => (string) ($row['game_id'] ?? ''),
            'game_name' => (string) ($row['game_name'] ?? ''),
            'tags'      => array_values(array_map('strval', (array) ($row['tags'] ?? []))),
        ];
    }

    /**
     * Modify Channel Information. Only the fields given are changed; a
     * null category leaves the channel's current one alone.
     *
     * @param list<string>|null $tags null leaves tags untouched; a list replaces them
     */
    public static function updateChannel(int $userId, string $title, ?string $categoryId, ?array $tags): bool
    {
        $conn = self::connection($userId);

        if ($conn === null) {
            self::$lastError = 'not_connected';
            return false;
        }

        $body = ['title' => $title];

        if ($categoryId !== null) {
            $body['game_id'] = $categoryId;
        }

        if ($tags !== null) {
            $body['tags'] = $tags;
        }

        $response = self::helixRaw($userId, 'PATCH', 'channels', ['broadcaster_id' => $conn['twitch_user_id']], $body);

        if ($response === null || $response['status'] !== 204) {
            if ($response !== null) {
                self::$lastError = Twitch::describe($response, 'helix/channels');
            }
            return false;
        }

        return true;
    }

    /**
     * Looks a game up among Twitch categories: an exact name match first,
     * then a search whose results are offered for the user to pick from.
     *
     * @return array{exact:?array{id:string,name:string}, candidates:list<array{id:string,name:string,art:?string}>}
     */
    public static function findCategory(int $userId, string $title): array
    {
        $exact = self::helix($userId, 'GET', 'games', ['name' => $title]);
        $hit   = $exact['data'][0] ?? null;

        if (is_array($hit) && isset($hit['id'])) {
            return ['exact' => ['id' => (string) $hit['id'], 'name' => (string) $hit['name']], 'candidates' => []];
        }

        $search = self::helix($userId, 'GET', 'search/categories', ['query' => $title, 'first' => 8]);
        $candidates = [];

        foreach ((array) ($search['data'] ?? []) as $row) {
            if (mb_strtolower((string) $row['name']) === mb_strtolower($title)) {
                return ['exact' => ['id' => (string) $row['id'], 'name' => (string) $row['name']], 'candidates' => []];
            }

            $candidates[] = [
                'id'   => (string) $row['id'],
                'name' => (string) $row['name'],
                'art'  => isset($row['box_art_url'])
                    ? str_replace(['{width}', '{height}'], ['52', '72'], (string) $row['box_art_url'])
                    : null,
            ];
        }

        return ['exact' => null, 'candidates' => $candidates];
    }

    /** @return array{id:string,name:string}|null a category by its id, to confirm a user's pick */
    public static function category(int $userId, string $id): ?array
    {
        $data = self::helix($userId, 'GET', 'games', ['id' => $id]);
        $row  = $data['data'][0] ?? null;

        return is_array($row) ? ['id' => (string) $row['id'], 'name' => (string) $row['name']] : null;
    }

    /** @return array<string,mixed> decoded JSON, or [] on any failure */
    private static function helix(int $userId, string $method, string $path, array $query, ?array $body = null): array
    {
        $response = self::helixRaw($userId, $method, $path, $query, $body);

        if ($response === null) {
            return [];
        }

        $data = Http::json($response);

        if ($data === null) {
            self::$lastError = Twitch::describe($response, 'helix/' . $path);
            return [];
        }

        return $data;
    }

    /**
     * One Helix call with the user's token. A 401 means the token lapsed
     * early or was revoked: refresh once and retry.
     *
     * @return array{status:int, body:string, error:?string}|null null when there is no usable token
     */
    private static function helixRaw(int $userId, string $method, string $path, array $query, ?array $body): ?array
    {
        foreach ([false, true] as $forceRefresh) {
            $token = self::accessToken($userId, $forceRefresh);

            if ($token === null) {
                return null;
            }

            $url     = 'https://api.twitch.tv/helix/' . $path . '?' . http_build_query($query);
            $headers = [
                'Client-Id'     => Twitch::clientId(),
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ];

            if ($method === 'GET' || $method === 'DELETE') {
                $response = $method === 'GET' ? Http::get($url, $headers) : Http::delete($url, $headers);
            } else {
                $headers['Content-Type'] = 'application/json';
                $response = $method === 'POST'
                    ? Http::post($url, (string) json_encode($body), $headers)
                    : Http::patch($url, (string) json_encode($body), $headers);
            }

            if ($response['status'] !== 401) {
                return $response;
            }
        }

        return $response;
    }

    /** A valid access token, refreshed when it is about to expire. */
    private static function accessToken(int $userId, bool $forceRefresh = false): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT access_token, refresh_token, expires_at < now() + interval \'2 minutes\' AS expiring
               FROM twitch_connections WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            self::$lastError = 'not_connected';
            return null;
        }

        if (!$forceRefresh && !$row['expiring']) {
            return Crypto::decrypt($row['access_token']);
        }

        $tokens = self::tokenRequest([
            'grant_type'    => 'refresh_token',
            'refresh_token' => (string) Crypto::decrypt($row['refresh_token']),
        ]);

        if ($tokens === null) {
            Database::connection()->prepare('DELETE FROM twitch_connections WHERE user_id = ?')->execute([$userId]);
            self::$lastError = 'reconnect';
            return null;
        }

        Database::connection()->prepare(
            'UPDATE twitch_connections
                SET access_token = ?, refresh_token = ?, expires_at = now() + make_interval(secs => ?)
              WHERE user_id = ?'
        )->execute([
            Crypto::encrypt($tokens['access_token']),
            Crypto::encrypt($tokens['refresh_token']),
            $tokens['expires_in'],
            $userId,
        ]);

        return $tokens['access_token'];
    }

    private static function storedToken(int $userId): ?string
    {
        $stmt = Database::connection()->prepare('SELECT access_token FROM twitch_connections WHERE user_id = ?');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : Crypto::decrypt((string) $value);
    }

    /**
     * POST to the token endpoint with the app's credentials.
     *
     * @return array{access_token:string, refresh_token:string, expires_in:int, scope:list<string>}|null
     */
    public static function tokenRequest(array $params): ?array
    {
        $response = Http::post('https://id.twitch.tv/oauth2/token', http_build_query($params + [
            'client_id'     => Twitch::clientId(),
            'client_secret' => Twitch::clientSecret(),
        ]), ['Content-Type' => 'application/x-www-form-urlencoded']);

        $data = Http::json($response);

        if ($data === null || empty($data['access_token']) || empty($data['refresh_token'])) {
            self::$lastError = Twitch::describe($response, 'oauth2/token');
            return null;
        }

        return [
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => (string) $data['refresh_token'],
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
            'scope'         => array_values(array_map('strval', (array) ($data['scope'] ?? []))),
        ];
    }
}
