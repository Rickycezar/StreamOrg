<?php
declare(strict_types=1);

/**
 * The people you collaborate with.
 *
 * User-owned, unlike the game catalogue: who you work with is personal.
 * A streamer can be typed in by hand or pulled from Twitch, and an
 * imported one keeps its Twitch user id so a rename does not orphan it.
 */
final class StreamerController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $pdo    = Database::connection();
        $userId = Auth::id();

        $filters = ['q' => trim((string) ($_GET['q'] ?? ''))];

        $sql = "SELECT s.*,
                       (SELECT count(*) FROM collab_streamers cs WHERE cs.streamer_id = s.id) AS collab_count,
                       (SELECT count(*) FROM stream_collaborators sc WHERE sc.streamer_id = s.id) AS stream_count,
                       (SELECT string_agg(sp.code || ':' || ch.handle, ', ' ORDER BY ch.is_primary DESC)
                          FROM streamer_channels ch
                          JOIN streaming_platforms sp ON sp.id = ch.streaming_platform_id
                         WHERE ch.streamer_id = s.id) AS channels
                  FROM streamers s
                 WHERE s.user_id = :user";

        $params = ['user' => $userId];

        if ($filters['q'] !== '') {
            $sql .= ' AND s.name ILIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $sql .= ' ORDER BY s.is_favorite DESC, s.name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        View::render('streamers/index', [
            'streamers'      => $stmt->fetchAll(),
            'filters'        => $filters,
            'platforms'      => $pdo->query('SELECT code FROM streaming_platforms WHERE is_enabled ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN),
            'twitchReady'    => Twitch::isConfigured(),
        ], __('ui.nav.streamers'));
    }

    /** Manual entry, for someone who is not on Twitch or not found there. */
    public static function store(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            flash('error', __('ui.message.invalid_input'));
            redirect('/streamers');
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'INSERT INTO streamers (user_id, name, email, notes, is_favorite)
             VALUES (:user, :name, :email, :notes, :fav)
             ON CONFLICT (user_id, name) DO NOTHING
             RETURNING id'
        );
        $stmt->bindValue('user', Auth::id(), PDO::PARAM_INT);
        $stmt->bindValue('name', $name);
        $stmt->bindValue('email', trim((string) ($_POST['email'] ?? '')) ?: null);
        $stmt->bindValue('notes', trim((string) ($_POST['notes'] ?? '')) ?: null);
        $stmt->bindValue('fav', !empty($_POST['is_favorite']), PDO::PARAM_BOOL);
        $stmt->execute();

        $id = $stmt->fetchColumn();

        if ($id === false) {
            flash('error', __('ui.message.duplicate'));
            redirect('/streamers');
        }

        self::saveChannel($pdo, (int) $id, (string) ($_POST['platform'] ?? ''), (string) ($_POST['handle'] ?? ''));

        flash('success', __('ui.message.saved'));
        redirect('/streamers');
    }

    /** AJAX: inline edit. */
    public static function update(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id   = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($id === false || $id === null || $name === '') {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'UPDATE streamers SET name = :name, email = :email, notes = :notes, is_favorite = :fav
              WHERE id = :id AND user_id = :user'
        );
        $stmt->bindValue('name', $name);
        $stmt->bindValue('email', trim((string) ($_POST['email'] ?? '')) ?: null);
        $stmt->bindValue('notes', trim((string) ($_POST['notes'] ?? '')) ?: null);
        $stmt->bindValue('fav', !empty($_POST['is_favorite']), PDO::PARAM_BOOL);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->bindValue('user', Auth::id(), PDO::PARAM_INT);

        try {
            $stmt->execute();
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                json_response(['ok' => false, 'error' => __('ui.message.duplicate')], 409);
            }

            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        self::saveChannel($pdo, $id, (string) ($_POST['platform'] ?? ''), (string) ($_POST['handle'] ?? ''));

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

        $stmt = Database::connection()->prepare('DELETE FROM streamers WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, Auth::id()]);

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        json_response(['ok' => true, 'message' => __('ui.message.deleted')]);
    }

    /** AJAX: search Twitch channels. */
    public static function search(): void
    {
        Auth::requireLogin();

        if (!Twitch::isConfigured()) {
            json_response(['ok' => false, 'error' => __('ui.message.twitch_unconfigured')], 400);
        }

        $term = trim((string) ($_GET['q'] ?? ''));

        if (mb_strlen($term) < 2) {
            json_response(['ok' => true, 'results' => []]);
        }

        try {
            $results = Twitch::search($term);
        } catch (Throwable $e) {
            ErrorLog::note('twitch search: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.provider_error')], 502);
        }

        json_response(['ok' => true, 'results' => $results]);
    }

    /** AJAX: import or refresh one Twitch profile. */
    public static function import(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        if (!Twitch::isConfigured()) {
            json_response(['ok' => false, 'error' => __('ui.message.twitch_unconfigured')], 400);
        }

        $ref = trim((string) ($_POST['ref'] ?? ''));

        if ($ref === '') {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $profile = Twitch::user($ref);

        if ($profile === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO streamers
                     (user_id, name, avatar_url, description, broadcaster_type,
                      source_provider, source_ref, source_synced_at)
                 VALUES (:user, :name, :avatar, :description, :type, 'twitch', :ref, now())
                 ON CONFLICT (user_id, source_provider, source_ref)
                 WHERE source_provider IS NOT NULL AND source_ref IS NOT NULL
                 DO UPDATE SET name             = EXCLUDED.name,
                               avatar_url       = COALESCE(EXCLUDED.avatar_url, streamers.avatar_url),
                               description      = COALESCE(EXCLUDED.description, streamers.description),
                               broadcaster_type = EXCLUDED.broadcaster_type,
                               source_synced_at = now()
                 RETURNING id, (xmax = 0) AS inserted"
            );
            $stmt->execute([
                'user'        => Auth::id(),
                'name'        => $profile['name'],
                'avatar'      => $profile['avatar'],
                'description' => $profile['description'],
                'type'        => $profile['broadcaster_type'],
                'ref'         => $profile['ref'],
            ]);

            $row = $stmt->fetch();

            self::saveChannel($pdo, (int) $row['id'], 'twitch', $profile['login'], $profile['url'], true);

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();

            if ($e->getCode() === '23505') {
                json_response(['ok' => false, 'error' => __('ui.message.streamer_name_taken')], 409);
            }

            throw $e;
        } catch (Throwable $e) {
            $pdo->rollBack();
            ErrorLog::note('twitch import: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => __('ui.message.server_error')], 500);
        }

        json_response([
            'ok'      => true,
            'id'      => (int) $row['id'],
            'name'    => $profile['name'],
            'created' => (bool) $row['inserted'],
            'message' => $row['inserted'] ? __('ui.message.imported') : __('ui.message.import_updated'),
        ]);
    }

    /** Upserts one channel for a streamer. Blank handle is a no-op. */
    private static function saveChannel(
        PDO $pdo,
        int $streamerId,
        string $platformCode,
        string $handle,
        ?string $url = null,
        bool $primary = false,
    ): void {
        $handle       = trim($handle);
        $platformCode = trim($platformCode);

        if ($handle === '' || $platformCode === '') {
            return;
        }

        $stmt = $pdo->prepare('SELECT id FROM streaming_platforms WHERE code = ? AND is_enabled');
        $stmt->execute([$platformCode]);
        $platformId = $stmt->fetchColumn();

        if ($platformId === false) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO streamer_channels (streamer_id, streaming_platform_id, handle, url, is_primary)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (streamer_id, streaming_platform_id, handle)
             DO UPDATE SET url = COALESCE(EXCLUDED.url, streamer_channels.url)'
        );
        $stmt->bindValue(1, $streamerId, PDO::PARAM_INT);
        $stmt->bindValue(2, $platformId, PDO::PARAM_INT);
        $stmt->bindValue(3, $handle);
        $stmt->bindValue(4, $url);
        $stmt->bindValue(5, $primary && !self::hasPrimary($pdo, $streamerId), PDO::PARAM_BOOL);
        $stmt->execute();
    }

    private static function hasPrimary(PDO $pdo, int $streamerId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM streamer_channels WHERE streamer_id = ? AND is_primary');
        $stmt->execute([$streamerId]);

        return $stmt->fetchColumn() !== false;
    }
}
