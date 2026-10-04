<?php
declare(strict_types=1);

/**
 * Embargoes: per user, per game, and several at a time.
 *
 * One game can be under more than one at once — a staggered console
 * release, or a 1.0 update with its own date — so each is its own row,
 * optionally scoped to a platform. Content is checked against the latest
 * one that applies to it.
 */
final class EmbargoController
{
    private const KINDS = ['release', 'review', 'update', 'dlc', 'other'];

    public static function index(): void
    {
        Auth::requireLogin();

        $pdo    = Database::connection();
        $userId = Auth::id();

        $stmt = $pdo->prepare(
            "SELECT e.*, g.title AS game_title, g.release_date, g.release_precision,
                    gp.code AS platform_code,
                    (e.lifts_at > now()) AS active,
                    (SELECT count(*) FROM game_keys k
                      WHERE k.game_id = e.game_id AND k.user_id = e.user_id) AS key_count
               FROM game_embargoes e
               JOIN games g                ON g.id = e.game_id
          LEFT JOIN game_platforms gp      ON gp.id = e.game_platform_id
              WHERE e.user_id = ?
           ORDER BY (e.lifts_at > now()) DESC, e.lifts_at, g.title"
        );
        $stmt->execute([$userId]);
        $embargoes = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT DISTINCT g.id, g.title, g.release_date, g.release_precision
               FROM game_keys k
               JOIN games g ON g.id = k.game_id
              WHERE k.user_id = :user
                AND NOT EXISTS (SELECT 1 FROM game_embargoes e
                                 WHERE e.game_id = g.id AND e.user_id = :user)
           ORDER BY g.title'
        );
        $stmt->execute(['user' => $userId]);
        $uncovered = $stmt->fetchAll();

        View::render('embargoes/index', [
            'embargoes' => $embargoes,
            'uncovered' => $uncovered,
            'kinds'     => self::KINDS,
            'platforms' => $pdo->query('SELECT code FROM game_platforms ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN),
        ], __('ui.nav.embargoes'));
    }

    public static function store(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $gameId = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);
        $lifts  = trim((string) ($_POST['lifts_at'] ?? ''));
        $kind   = in_array($_POST['kind'] ?? '', self::KINDS, true) ? $_POST['kind'] : 'release';

        if (!$gameId || $lifts === '') {
            flash('error', __('ui.message.invalid_input'));
            redirect('/embargoes');
        }

        $pdo = Database::connection();

        $platformId = null;
        $platform   = trim((string) ($_POST['game_platform'] ?? ''));

        if ($platform !== '') {
            $stmt = $pdo->prepare('SELECT id FROM game_platforms WHERE code = ?');
            $stmt->execute([$platform]);
            $found = $stmt->fetchColumn();

            if ($found === false) {
                flash('error', __('ui.message.invalid_input'));
                redirect('/embargoes');
            }

            $platformId = (int) $found;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO game_embargoes
                 (user_id, game_id, game_platform_id, kind, label, lifts_at, source, note)
             VALUES (?, ?, ?, ?, ?, ?, 'manual', ?)
             ON CONFLICT (user_id, game_id, kind, COALESCE(game_platform_id, 0))
             DO UPDATE SET lifts_at = EXCLUDED.lifts_at,
                           label    = EXCLUDED.label,
                           note     = EXCLUDED.note,
                           source   = 'manual'"
        );
        $stmt->execute([
            Auth::id(), $gameId, $platformId, $kind,
            trim((string) ($_POST['label'] ?? '')) ?: null,
            $lifts,
            trim((string) ($_POST['note'] ?? '')) ?: null,
        ]);

        flash('success', __('ui.message.saved'));
        redirect('/embargoes');
    }

    /** AJAX: change one embargo's date in place. */
    public static function update(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id    = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $lifts = trim((string) ($_POST['lifts_at'] ?? ''));

        if ($id === false || $id === null || $lifts === '') {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $stmt = Database::connection()->prepare(
            "UPDATE game_embargoes
                SET lifts_at = :lifts, label = :label, note = :note, source = 'manual'
              WHERE id = :id AND user_id = :user"
        );
        $stmt->execute([
            'lifts' => $lifts,
            'label' => trim((string) ($_POST['label'] ?? '')) ?: null,
            'note'  => trim((string) ($_POST['note'] ?? '')) ?: null,
            'id'    => $id,
            'user'  => Auth::id(),
        ]);

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        json_response(['ok' => true, 'message' => __('ui.message.saved')]);
    }

    public static function delete(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $stmt = Database::connection()->prepare(
            'DELETE FROM game_embargoes WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, Auth::id()]);

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        json_response(['ok' => true, 'message' => __('ui.message.deleted')]);
    }
}
