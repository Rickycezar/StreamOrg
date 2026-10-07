<?php
declare(strict_types=1);

/**
 * The catalogue as ordinary users see it.
 *
 * Anyone signed in may add a game, publisher or developer — but only from
 * an external source, never by typing one in, so the shared catalogue
 * stays consistent. With Twitch configured, games are added Twitch first:
 * the search lists Twitch categories, and the game's details are filled in
 * from Steam or IGDB (GameCatalog::addFromTwitch), so every game has its
 * category from the start. Without Twitch, the provider flagged default
 * is used, never offering a choice.
 *
 * Admins keep /admin/import, where the provider is selectable.
 */
final class CatalogController
{
    /** The provider marked default, when it is usable. */
    public static function defaultProvider(): ?Provider
    {
        $code = Database::connection()
            ->query('SELECT provider FROM api_settings WHERE is_default LIMIT 1')
            ->fetchColumn();

        if ($code === false) {
            return null;
        }

        $provider = Providers::get((string) $code);

        return $provider !== null && $provider->isAvailable() ? $provider : null;
    }

    /** Whether games can be added at all: through Twitch, or the default provider. */
    public static function canAddGames(): bool
    {
        return Twitch::isConfigured() || self::defaultProvider() !== null;
    }

    public static function index(): void
    {
        Auth::requireLogin();

        $pdo = Database::connection();

        $filters = ['q' => trim((string) ($_GET['q'] ?? '')), 'stale' => (string) ($_GET['stale'] ?? '')];

        $sql = "SELECT g.*, p.name AS publisher_name, d.name AS developer_name,
                       t.path AS thumb_path, t.updated_at AS thumb_version,
                       (SELECT count(*) FROM game_keys k WHERE k.game_id = g.id AND k.user_id = :user) AS my_keys,
                       (SELECT count(*) FROM game_embargoes e WHERE e.game_id = g.id AND e.user_id = :user) AS my_embargoes
                  FROM games g
             LEFT JOIN publishers p ON p.id = g.publisher_id
             LEFT JOIN developers d ON d.id = g.developer_id
             LEFT JOIN game_images t ON t.game_id = g.id AND t.kind = 'thumb'
                 WHERE 1 = 1";

        $params = ['user' => Auth::id()];

        if ($filters['q'] !== '') {
            $sql .= ' AND g.title ILIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if ($filters['stale'] === '1') {
            $sql .= " AND (g.release_date IS NULL OR g.release_precision <> 'day')";
        }

        $sql .= ' ORDER BY g.title';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $games = $stmt->fetchAll();

        $needsDate = (int) $pdo->query(
            "SELECT count(*) FROM games WHERE release_date IS NULL OR release_precision <> 'day'"
        )->fetchColumn();

        $provider = self::defaultProvider();

        View::render('catalog/index', [
            'games'        => $games,
            'filters'      => $filters,
            'needsDate'    => $needsDate,
            'provider'     => $provider,
            'providerCode' => $provider?->code(),
            'twitchFirst'  => Twitch::isConfigured(),
        ], __('ui.nav.catalog'));
    }

    /**
     * AJAX: search Twitch's categories (or, without Twitch, the default
     * provider). No provider parameter is accepted. Twitch results say
     * whether the catalogue already has that game.
     */
    public static function search(): void
    {
        Auth::requireLogin();

        $provider = self::defaultProvider();

        if (!Twitch::isConfigured() && $provider === null) {
            json_response(['ok' => false, 'error' => __('ui.message.no_default_provider')], 400);
        }

        $term = trim((string) ($_GET['q'] ?? ''));

        if (mb_strlen($term) < 2) {
            json_response(['ok' => true, 'results' => []]);
        }

        try {
            $results = Twitch::isConfigured() ? self::twitchResults($term) : $provider->search($term);
        } catch (Throwable $e) {
            ErrorLog::note('catalog search: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.provider_error')], 502);
        }

        json_response(['ok' => true, 'results' => $results]);
    }

    /** @return list<array{ref:string, title:string, year:?string, image:?string, known:bool}> */
    private static function twitchResults(string $term): array
    {
        $categories = Twitch::searchCategories($term);

        if ($categories === []) {
            return [];
        }

        $ids  = array_column($categories, 'id');
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT twitch_category_id FROM games
              WHERE twitch_category_source <> 'default'
                AND twitch_category_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
        $known = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

        return array_map(static fn (array $c): array => [
            'ref'   => $c['id'],
            'title' => $c['name'],
            'year'  => null,
            'image' => $c['cover'],
            'known' => isset($known[$c['id']]),
        ], $categories);
    }

    /** AJAX: add a Twitch category's game (or import a default-provider result). */
    public static function import(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $ref = trim((string) ($_POST['ref'] ?? ''));

        if (Twitch::isConfigured()) {
            self::addFromTwitch($ref);
        }

        $provider = self::defaultProvider();

        if ($provider === null || $ref === '') {
            json_response(['ok' => false, 'error' => __('ui.message.no_default_provider')], 400);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT g.id, g.title, g.release_precision, d.name AS developer, p.name AS publisher
               FROM games g
          LEFT JOIN developers d ON d.id = g.developer_id
          LEFT JOIN publishers p ON p.id = g.publisher_id
              WHERE g.source_provider = ? AND g.source_ref = ?
                AND g.source_synced_at > now() - make_interval(mins => ?)"
        );
        $stmt->execute([$provider->code(), $ref, GameCatalog::REFRESH_COOLDOWN_MINUTES]);
        $recent = $stmt->fetch();

        if ($recent !== false) {
            json_response([
                'ok'        => true,
                'id'        => (int) $recent['id'],
                'title'     => $recent['title'],
                'created'   => false,
                'precision' => $recent['release_precision'],
                'developer' => $recent['developer'],
                'publisher' => $recent['publisher'],
                'message'   => __('ui.message.import_updated'),
            ]);
        }

        $pdo->beginTransaction();

        try {
            $result = GameCatalog::importFromProvider($pdo, $provider, $ref);
            GameCatalog::syncReleaseEmbargo($pdo, (int) Auth::id(), $result['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            ErrorLog::note('catalog import: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.server_error')], 500);
        }

        json_response([
            'ok'        => true,
            'id'        => $result['id'],
            'title'     => $result['title'],
            'created'   => $result['created'],
            'precision' => $result['precision'],
            'developer' => $result['developer'],
            'publisher' => $result['publisher'],
            'message'   => $result['created'] ? __('ui.message.imported') : __('ui.message.import_updated'),
        ]);
    }

    /** Responds with the game a Twitch category stands for, adding it when new. */
    private static function addFromTwitch(string $categoryId): never
    {
        try {
            $result = Database::transaction(static function (PDO $pdo) use ($categoryId): array {
                $result = GameCatalog::addFromTwitch($pdo, $categoryId);
                GameCatalog::syncReleaseEmbargo($pdo, (int) Auth::id(), $result['id']);

                return $result;
            });
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            ErrorLog::note('add from Twitch: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.server_error')], 500);
        }

        json_response([
            'ok'        => true,
            'id'        => $result['id'],
            'title'     => $result['title'],
            'created'   => $result['created'],
            'precision' => $result['precision'],
            'developer' => $result['developer'],
            'publisher' => $result['publisher'],
            'message'   => __($result['created'] ? 'ui.message.imported' : 'ui.message.already_in_catalog'),
        ]);
    }

    /**
     * AJAX: ask the provider for fresh data on a game already in the
     * catalogue. Any signed-in user may request this — it updates shared
     * data from an external source rather than asserting anything new.
     */
    public static function refresh(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $gameId = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);

        if ($gameId === false || $gameId === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $result = GameCatalog::refresh($pdo, $gameId);
            GameCatalog::syncReleaseEmbargo($pdo, (int) Auth::id(), $result['id']);
            $pdo->commit();
        } catch (UserError $e) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            $pdo->rollBack();
            ErrorLog::note('catalog refresh: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.server_error')], 500);
        }

        json_response([
            'ok'        => true,
            'title'     => $result['title'],
            'thumb'     => GameImages::urls($pdo, $result['id'])['thumb'] ?? null,
            'release'   => $result['release'] ? fmt_date($result['release']) : null,
            'precision' => $result['precision'],
            'message'   => $result['precision'] === 'day'
                ? __('ui.message.refreshed_exact')
                : __('ui.message.refreshed_vague'),
        ]);
    }
}
