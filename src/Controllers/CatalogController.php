<?php
declare(strict_types=1);

/**
 * The catalogue as ordinary users see it.
 *
 * Anyone signed in may add a game, publisher or developer — but only by
 * importing from a provider, never by typing one in, so the shared
 * catalogue stays consistent with an external source. Which provider is
 * used is an administrative decision: this controller always uses the one
 * flagged default and never offers a choice.
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
        ], __('ui.nav.catalog'));
    }

    /** AJAX: search the default provider. No provider parameter is accepted. */
    public static function search(): void
    {
        Auth::requireLogin();

        $provider = self::defaultProvider();

        if ($provider === null) {
            json_response(['ok' => false, 'error' => __('ui.message.no_default_provider')], 400);
        }

        $term = trim((string) ($_GET['q'] ?? ''));

        if (mb_strlen($term) < 2) {
            json_response(['ok' => true, 'results' => []]);
        }

        try {
            $results = $provider->search($term);
        } catch (Throwable $e) {
            error_log('StreamOrg catalog search: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.provider_error')], 502);
        }

        json_response(['ok' => true, 'results' => $results]);
    }

    /** AJAX: import a result through the default provider. */
    public static function import(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $provider = self::defaultProvider();
        $ref      = trim((string) ($_POST['ref'] ?? ''));

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

            error_log('StreamOrg catalog import: ' . $e->getMessage());
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
            error_log('StreamOrg catalog refresh: ' . $e->getMessage());
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
