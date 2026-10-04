<?php
declare(strict_types=1);

/**
 * Catalogue import: search an external provider, then pull one result into
 * the local games/publishers/developers tables.
 *
 * Every screen here degrades gracefully — with no provider available the
 * page explains what to configure instead of erroring.
 */
final class ImportController
{
    public static function index(): void
    {
        Auth::requireAdmin();

        View::render('admin/import', [
            'providers' => Providers::available(),
            'all'       => Providers::all(),
        ], __('ui.nav.import'));
    }

    /** AJAX: search one provider. */
    public static function search(): void
    {
        Auth::requireAdmin();

        $code = (string) ($_GET['provider'] ?? '');
        $term = trim((string) ($_GET['q'] ?? ''));

        $provider = Providers::get($code);

        if ($provider === null || !$provider->isAvailable()) {
            json_response(['ok' => false, 'error' => __('ui.message.provider_unavailable')], 400);
        }

        if (mb_strlen($term) < 2) {
            json_response(['ok' => true, 'results' => []]);
        }

        try {
            $results = $provider->search($term);
        } catch (Throwable $e) {
            error_log('StreamOrg import search: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.provider_error')], 502);
        }

        json_response(['ok' => true, 'results' => $results]);
    }

    /**
     * AJAX: import one result.
     *
     * Publishers and developers are created on demand. Re-importing the same
     * external record updates the existing row rather than duplicating it,
     * which is what the (source_provider, source_ref) unique indexes enforce.
     */
    public static function import(): void
    {
        Auth::requireAdmin();
        Csrf::verify(json: true);

        $code = (string) ($_POST['provider'] ?? '');
        $ref  = trim((string) ($_POST['ref'] ?? ''));

        $provider = Providers::get($code);

        if ($provider === null || !$provider->isAvailable() || $ref === '') {
            json_response(['ok' => false, 'error' => __('ui.message.provider_unavailable')], 400);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $result = GameCatalog::importFromProvider($pdo, $provider, $ref);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('StreamOrg import: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.server_error')], 500);
        }

        json_response([
            'ok'        => true,
            'id'        => $result['id'],
            'title'     => $result['title'],
            'created'   => $result['created'],
            'developer' => $result['developer'],
            'publisher' => $result['publisher'],
            'message' => $result['created'] ? __('ui.message.imported') : __('ui.message.import_updated'),
        ]);
    }
}
