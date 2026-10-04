<?php
declare(strict_types=1);

/**
 * Search endpoints behind the Tom Select pickers.
 *
 * Pages no longer embed every game in every <select>: a picker renders only
 * its current choice and asks here as the user types. Read-only and open to
 * any signed-in user — the catalogue is shared.
 */
final class PickerController
{
    private const LIMIT = 30;

    /** Tables a company picker may search; the request picks by key, never by name. */
    private const COMPANY_TABLES = ['publishers' => 'publisher_id', 'developers' => 'developer_id'];

    /**
     * GET /pickers/games?q=…  or  ?ids=1,2
     *
     * Each item carries what the picker shows (cover, year, studios) and
     * what the content form's title helper needs (developer, publisher).
     */
    public static function games(): void
    {
        Auth::requireLogin();

        $sql = "SELECT g.id, g.title, g.cover_url,
                       extract(year FROM g.release_date)::int AS year,
                       d.name AS developer, p.name AS publisher,
                       t.path AS thumb_path, t.updated_at AS thumb_version
                  FROM games g
             LEFT JOIN developers d ON d.id = g.developer_id
             LEFT JOIN publishers p ON p.id = g.publisher_id
             LEFT JOIN game_images t ON t.game_id = g.id AND t.kind = 'thumb'";

        [$where, $params, $order] = self::filter('g.title', 'g.id');

        $stmt = Database::connection()->prepare("{$sql} {$where} {$order} LIMIT " . self::LIMIT);
        $stmt->execute($params);

        json_response(['ok' => true, 'items' => array_map(static fn (array $g): array => [
            'id'        => (int) $g['id'],
            'title'     => $g['title'],
            'year'      => $g['year'] === null ? null : (int) $g['year'],
            'cover'     => $g['thumb_path'] !== null
                ? GameImages::publicUrl($g['thumb_path'], (string) $g['thumb_version'])
                : $g['cover_url'],
            'developer' => $g['developer'],
            'publisher' => $g['publisher'],
        ], $stmt->fetchAll())]);
    }

    /** GET /pickers/companies?type=publishers|developers&q=… */
    public static function companies(): void
    {
        Auth::requireLogin();

        $type = (string) ($_GET['type'] ?? '');

        if (!isset(self::COMPANY_TABLES[$type])) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $column = self::COMPANY_TABLES[$type];

        [$where, $params, $order] = self::filter('c.name', 'c.id');

        $stmt = Database::connection()->prepare(
            "SELECT c.id, c.name, (SELECT count(*) FROM games g WHERE g.{$column} = c.id) AS games
               FROM {$type} c {$where} {$order} LIMIT " . self::LIMIT
        );
        $stmt->execute($params);

        json_response(['ok' => true, 'items' => array_map(static fn (array $c): array => [
            'id'    => (int) $c['id'],
            'name'  => $c['name'],
            'games' => (int) $c['games'],
        ], $stmt->fetchAll())]);
    }

    /**
     * Shared search: `ids` fetches known rows (to label a preselected value),
     * otherwise `q` matches anywhere in the name, ranking names that start
     * with it first.
     *
     * @return array{0:string, 1:array<string,mixed>, 2:string}
     */
    private static function filter(string $nameColumn, string $idColumn): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))
        ))), 0, 100);

        if ($ids !== []) {
            $marks  = implode(',', array_map(static fn (int $i): string => ':id' . $i, array_keys($ids)));
            $params = [];

            foreach ($ids as $i => $id) {
                $params['id' . $i] = $id;
            }

            return ["WHERE {$idColumn} IN ({$marks})", $params, "ORDER BY {$nameColumn}"];
        }

        $q = trim((string) ($_GET['q'] ?? ''));

        if ($q === '') {
            return ['', [], "ORDER BY {$nameColumn}"];
        }

        $like = '%' . addcslashes($q, '%_\\') . '%';

        return [
            "WHERE {$nameColumn} ILIKE :like",
            ['like' => $like, 'prefix' => addcslashes($q, '%_\\') . '%'],
            "ORDER BY ({$nameColumn} ILIKE :prefix) DESC, {$nameColumn}",
        ];
    }
}
