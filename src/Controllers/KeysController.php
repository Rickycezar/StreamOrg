<?php
declare(strict_types=1);

/**
 * The keys vault.
 *
 * Key codes are never included in the list response. The listing carries
 * only metadata; the code itself is fetched one row at a time through
 * reveal(), which re-checks ownership on every call.
 */
final class KeysController
{
    /** Public so other screens offering a key status use this list, not their own. */
    public const STATUSES = ['available', 'reserved', 'for_giveaway', 'used', 'given_away', 'expired', 'revoked'];

    /** Statuses that put a key in front of an audience. */
    private const GIVEAWAY_STATUSES = ['for_giveaway', 'given_away'];

    /**
     * Blocks putting an embargoed game's key into a giveaway.
     *
     * The client confirms first, but the check lives here too: a warning
     * that can be skipped by posting directly is not a guard. confirm=1
     * records that the choice was made deliberately.
     *
     * @return array{game:string, embargo:string}|null null when nothing to warn about
     */
    private static function embargoBlock(PDO $pdo, int $keyId, string $status, ?int $gameId = null, ?int $platformId = null): ?array
    {
        if (!in_array($status, self::GIVEAWAY_STATUSES, true) || !empty($_POST['confirm'])) {
            return null;
        }

        if ($gameId === null || $platformId === null) {
            $stmt = $pdo->prepare('SELECT game_id, game_platform_id FROM game_keys WHERE id = ? AND user_id = ?');
            $stmt->execute([$keyId, Auth::id()]);
            $key = $stmt->fetch();

            if ($key === false) {
                return null;
            }

            $gameId     ??= (int) $key['game_id'];
            $platformId ??= (int) $key['game_platform_id'];
        }

        return self::embargoFor($pdo, $gameId, $platformId);
    }

    /**
     * A key waiting as a prize in a giveaway keeps the "for giveaway"
     * status until it is claimed or taken out of the giveaway.
     */
    private static function guardGiveawayPrize(int $keyId, string $status): void
    {
        if ($status === 'for_giveaway') {
            return;
        }

        $giveaway = Giveaways::prizeOf((int) Auth::id(), $keyId);

        if ($giveaway !== null) {
            json_response(['ok' => false, 'error' => sprintf(__('ui.message.key_in_giveaway'), $giveaway)], 409);
        }
    }

    /**
     * The binding embargo for a game on a redemption platform: the latest
     * one that either names that platform or names none at all.
     *
     * @return array{game:string, embargo:string}|null
     */
    private static function embargoFor(PDO $pdo, int $gameId, int $platformId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT e.lifts_at AS embargo_until, g.title
               FROM games g
               JOIN game_embargoes e ON e.game_id = g.id AND e.user_id = ?
              WHERE g.id = ?
                AND e.lifts_at > now()
                AND (e.game_platform_id IS NULL OR e.game_platform_id = ?)
           ORDER BY e.lifts_at DESC
              LIMIT 1'
        );
        $stmt->execute([Auth::id(), $gameId, $platformId]);
        $row = $stmt->fetch();

        return $row === false
            ? null
            : ['game' => $row['title'], 'embargo' => fmt_datetime($row['embargo_until'])];
    }

    /** Emits the 409 that asks the client to confirm. */
    private static function embargoConfirmResponse(array $block): never
    {
        json_response([
            'ok'      => false,
            'confirm' => true,
            'title'   => __('ui.message.embargo_giveaway_title'),
            'error'   => sprintf(__('ui.message.embargo_giveaway'), $block['game'], $block['embargo']),
        ], 409);
    }

    /**
     * Stops a request that has to read or write codes while the user's
     * private vault is locked. The keys page offers the unlock form.
     */
    private static function requireVault(bool $json): void
    {
        if (Vault::isUnlocked((int) Auth::id())) {
            return;
        }

        if ($json) {
            json_response(['ok' => false, 'locked' => true, 'error' => __('ui.message.vault_locked')], 423);
        }

        flash('error', __('ui.message.vault_locked'));
        redirect('/keys');
    }

    /**
     * The key list's query, without filters or ordering. Shared by the list
     * and by the on-demand edit form, so both see a key the same way.
     * Deliberately no k.key_code in the select list.
     */
    private static function listSql(): string
    {
        return "SELECT k.id, k.key_type, k.content_type, k.status, k.region,
                       k.received_at, k.expires_at, k.notes,
                       emb.lifts_at AS embargo_until,
                       (emb.lifts_at IS NOT NULL AND emb.lifts_at > now()) AS under_embargo,
                       k.game_id, g.title AS game_title, g.release_date,
                       gp.code AS game_platform_code, gp.family AS game_platform_family,
                       kp.code AS key_platform_code,
                       (k.key_type = 'review' AND g.release_date IS NOT NULL
                        AND g.release_date > CURRENT_DATE) AS playable_early
                  FROM game_keys k
                  JOIN games g            ON g.id  = k.game_id
                  JOIN game_platforms gp  ON gp.id = k.game_platform_id
                  JOIN key_platforms kp   ON kp.id = k.key_platform_id
             LEFT JOIN LATERAL (
                     SELECT e.lifts_at FROM game_embargoes e
                      WHERE e.game_id = k.game_id AND e.user_id = k.user_id
                        AND (e.game_platform_id IS NULL OR e.game_platform_id = k.game_platform_id)
                   ORDER BY e.lifts_at DESC LIMIT 1
                 ) emb ON true
                 WHERE k.user_id = :user_id";
    }

    public static function index(): void
    {
        Auth::requireLogin();

        $pdo    = Database::connection();
        $userId = Auth::id();

        $filters = [
            'status'       => (string) ($_GET['status'] ?? ''),
            'key_type'     => (string) ($_GET['key_type'] ?? ''),
            'content_type' => (string) ($_GET['content_type'] ?? ''),
            'platform'     => (string) ($_GET['platform'] ?? ''),
            'source'       => (string) ($_GET['source'] ?? ''),
            'q'            => trim((string) ($_GET['q'] ?? '')),
        ];

        $sql = self::listSql();

        $params = ['user_id' => $userId];

        if (in_array($filters['status'], self::STATUSES, true)) {
            $sql .= ' AND k.status = :status';
            $params['status'] = $filters['status'];
        }

        if (in_array($filters['key_type'], ['common', 'review'], true)) {
            $sql .= ' AND k.key_type = :key_type';
            $params['key_type'] = $filters['key_type'];
        }

        if (in_array($filters['content_type'], ['game', 'dlc'], true)) {
            $sql .= ' AND k.content_type = :content_type';
            $params['content_type'] = $filters['content_type'];
        }

        if ($filters['platform'] !== '') {
            $sql .= ' AND gp.code = :platform';
            $params['platform'] = $filters['platform'];
        }

        if ($filters['source'] !== '') {
            $sql .= ' AND kp.code = :source';
            $params['source'] = $filters['source'];
        }

        if ($filters['q'] !== '') {
            $sql .= ' AND g.title ILIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $sql .= ' ORDER BY g.title, gp.code';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $keys = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT status, count(*) AS total FROM game_keys WHERE user_id = ? GROUP BY status');
        $stmt->execute([$userId]);
        $counts = array_column($stmt->fetchAll(), 'total', 'status');

        View::render('keys/index', [
            'keys'      => $keys,
            'counts'    => $counts,
            'filters'   => $filters,
            'statuses'  => self::STATUSES,
            'platforms' => $pdo->query('SELECT code, family FROM game_platforms ORDER BY sort_order')->fetchAll(),
            'sources'   => $pdo->query('SELECT code FROM key_platforms ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN),
            'gamePlatforms' => $pdo->query('SELECT code FROM game_platforms ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN),
            'canAddGames' => CatalogController::defaultProvider() !== null,
            'vaultLocked' => !Vault::isUnlocked($userId),
        ], __('ui.nav.keys'));
    }

    /**
     * GET: one key's edit form, as an HTML fragment. Each row used to carry
     * its own copy of every option list, which made up most of the page;
     * now a form is only built when its Edit button is used.
     */
    public static function editForm(): void
    {
        Auth::requireLogin();

        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        $stmt = Database::connection()->prepare(self::listSql() . ' AND k.id = :id');
        $stmt->execute(['user_id' => Auth::id(), 'id' => $id ?: 0]);
        $key = $stmt->fetch();

        if ($key === false) {
            http_response_code(404);
            exit;
        }

        $pdo = Database::connection();

        echo View::partial('keys/edit_form', [
            'key'           => $key,
            'statuses'      => self::STATUSES,
            'sources'       => $pdo->query('SELECT code FROM key_platforms ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN),
            'gamePlatforms' => $pdo->query('SELECT code FROM game_platforms ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN),
        ]);
    }

    /**
     * AJAX: returns one key code.
     *
     * The WHERE clause carries user_id as well as id, so a guessed or
     * tampered id belonging to someone else yields nothing rather than
     * another user's key.
     */
    public static function reveal(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);
        self::requireVault(json: true);

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $stmt = Database::connection()->prepare(
            'SELECT key_code FROM game_keys WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, Auth::id()]);
        $code = $stmt->fetchColumn();

        if ($code === false) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        $plain = Vault::decryptCode((int) Auth::id(), (string) $code);

        if ($plain === null) {
            json_response(['ok' => false, 'error' => __('ui.message.key_unreadable')], 410);
        }

        json_response(['ok' => true, 'key_code' => $plain]);
    }

    /** AJAX: moves a key to another status. */
    public static function updateStatus(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');

        if ($id === false || $id === null || !in_array($status, self::STATUSES, true)) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $pdo = Database::connection();

        self::guardGiveawayPrize($id, $status);

        if (($block = self::embargoBlock($pdo, $id, $status)) !== null) {
            self::embargoConfirmResponse($block);
        }

        $stmt = $pdo->prepare(
            'UPDATE game_keys SET status = ? WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$status, $id, Auth::id()]);

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        json_response([
            'ok'     => true,
            'status' => $status,
            'label'  => code_label('key_status', $status),
        ]);
    }

    public static function store(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        self::requireVault(json: false);

        $gameId   = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);
        $platform = trim((string) ($_POST['game_platform'] ?? ''));
        $source   = trim((string) ($_POST['key_platform'] ?? ''));
        $codes    = trim((string) ($_POST['key_code'] ?? ''));

        if (!$gameId || $platform === '' || $source === '' || $codes === '') {
            flash('error', __('ui.message.invalid_input'));
            redirect('/keys');
        }

        $pdo = Database::connection();

        $lookup = $pdo->prepare('SELECT id FROM game_platforms WHERE code = ?');
        $lookup->execute([$platform]);
        $platformId = $lookup->fetchColumn();

        $lookup = $pdo->prepare('SELECT id FROM key_platforms WHERE code = ?');
        $lookup->execute([$source]);
        $sourceId = $lookup->fetchColumn();

        if ($platformId === false || $sourceId === false) {
            flash('error', __('ui.message.invalid_input'));
            redirect('/keys');
        }

        $keyType     = in_array($_POST['key_type'] ?? '', ['common', 'review'], true) ? $_POST['key_type'] : 'common';
        $contentType = in_array($_POST['content_type'] ?? '', ['game', 'dlc'], true) ? $_POST['content_type'] : 'game';
        $status      = in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : 'available';

        if (in_array($status, self::GIVEAWAY_STATUSES, true)
            && ($block = self::embargoFor($pdo, (int) $gameId, (int) $platformId)) !== null) {
            flash('error', sprintf(__('ui.message.embargo_giveaway'), $block['game'], $block['embargo']));
            redirect('/keys');
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $codes) ?: [])));

        $expires = trim((string) ($_POST['expires_at'] ?? '')) ?: null;

        $insert = $pdo->prepare(
            'INSERT INTO game_keys
                 (user_id, game_id, key_platform_id, game_platform_id, key_code, key_hash,
                  key_type, content_type, status, notes, expires_at, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now())
             ON CONFLICT (user_id, game_platform_id, key_hash) WHERE key_hash IS NOT NULL DO NOTHING'
        );

        $added  = 0;
        $notes  = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $userId = (int) Auth::id();

        foreach ($lines as $line) {
            $insert->execute([
                $userId, $gameId, $sourceId, $platformId,
                Vault::encryptCode($userId, $line), Vault::hashCode($userId, $line),
                $keyType, $contentType, $status, $notes, $expires,
            ]);
            $added += $insert->rowCount();
        }

        $skipped = count($lines) - $added;

        flash('success', sprintf(__('ui.message.keys_added'), $added)
            . ($skipped > 0 ? ' ' . sprintf(__('ui.message.keys_skipped'), $skipped) : ''));

        redirect('/keys');
    }

    /**
     * AJAX: add a single key and hand back enough to drop it straight into a
     * <select>. Used by the content form so registering a key does not cost
     * you the half-filled form you were on.
     */
    public static function quickStore(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);
        self::requireVault(json: true);

        $gameId   = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);
        $platform = trim((string) ($_POST['game_platform'] ?? ''));
        $source   = trim((string) ($_POST['key_platform'] ?? ''));
        $code     = trim((string) ($_POST['key_code'] ?? ''));

        if (!$gameId || $platform === '' || $source === '' || $code === '') {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT id FROM game_platforms WHERE code = ?');
        $stmt->execute([$platform]);
        $platformId = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT id FROM key_platforms WHERE code = ?');
        $stmt->execute([$source]);
        $sourceId = $stmt->fetchColumn();

        if ($platformId === false || $sourceId === false) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $keyType     = in_array($_POST['key_type'] ?? '', ['common', 'review'], true) ? $_POST['key_type'] : 'common';
        $contentType = in_array($_POST['content_type'] ?? '', ['game', 'dlc'], true) ? $_POST['content_type'] : 'game';
        $status      = in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : 'available';

        if (in_array($status, self::GIVEAWAY_STATUSES, true) && empty($_POST['confirm'])
            && ($block = self::embargoFor($pdo, (int) $gameId, (int) $platformId)) !== null) {
            self::embargoConfirmResponse($block);
        }

        $expires = trim((string) ($_POST['expires_at'] ?? '')) ?: null;

        $stmt = $pdo->prepare(
            "INSERT INTO game_keys
                 (user_id, game_id, key_platform_id, game_platform_id, key_code, key_hash,
                  key_type, content_type, status, expires_at, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now())
             ON CONFLICT (user_id, game_platform_id, key_hash) WHERE key_hash IS NOT NULL DO NOTHING
             RETURNING id"
        );
        $userId = (int) Auth::id();
        $stmt->execute([$userId, $gameId, $sourceId, $platformId,
                        Vault::encryptCode($userId, $code), Vault::hashCode($userId, $code),
                        $keyType, $contentType, $status, $expires]);

        $id = $stmt->fetchColumn();

        if ($id === false) {
            json_response(['ok' => false, 'error' => __('ui.message.duplicate')], 409);
        }

        json_response([
            'ok'      => true,
            'id'      => (int) $id,
            'game_id' => $gameId,
            'label'   => sprintf(
                '%s · %s · %s',
                code_label('game_platform', $platform),
                code_label('key_type', $keyType),
                code_label('key_status', $status)
            ),
        ]);
    }

    /**
     * AJAX: inline edit of one key.
     *
     * key_code is editable here on purpose — imported placeholders such as
     * UNREVEALED-HUMBLE-07 exist precisely so the real code can be pasted in
     * once it is revealed.
     */
    public static function update(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $newCode = trim((string) ($_POST['key_code'] ?? ''));

        if ($newCode !== '') {
            self::requireVault(json: true);
        }

        $status      = in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : null;
        $keyType     = in_array($_POST['key_type'] ?? '', ['common', 'review'], true) ? $_POST['key_type'] : null;
        $contentType = in_array($_POST['content_type'] ?? '', ['game', 'dlc'], true) ? $_POST['content_type'] : null;

        if ($status === null || $keyType === null || $contentType === null) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $pdo = Database::connection();

        $targetGame = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT) ?: null;
        $targetPlatform = null;

        if (trim((string) ($_POST['game_platform'] ?? '')) !== '') {
            $lookup = $pdo->prepare('SELECT id FROM game_platforms WHERE code = ?');
            $lookup->execute([trim((string) $_POST['game_platform'])]);
            $targetPlatform = ($found = $lookup->fetchColumn()) === false ? null : (int) $found;
        }

        self::guardGiveawayPrize($id, $status);

        if (($block = self::embargoBlock($pdo, $id, $status, $targetGame, $targetPlatform)) !== null) {
            self::embargoConfirmResponse($block);
        }

        $sets = [
            'key_type     = :key_type',
            'content_type = :content_type',
            'status       = :status',
        ];

        $params = [
            'key_type'     => $keyType,
            'content_type' => $contentType,
            'status'       => $status,
            'id'           => $id,
            'user'         => Auth::id(),
        ];

        if ($newCode !== '') {
            $sets[] = 'key_code = :code';
            $sets[] = 'key_hash = :hash';
            $sets[] = 'is_placeholder = false';
            $params['code'] = Vault::encryptCode((int) Auth::id(), $newCode);
            $params['hash'] = Vault::hashCode((int) Auth::id(), $newCode);
        }

        $lookups = [
            'key_platform'  => ['key_platforms',  'key_platform_id'],
            'game_platform' => ['game_platforms', 'game_platform_id'],
        ];

        foreach ($lookups as $field => [$table, $column]) {
            $code = trim((string) ($_POST[$field] ?? ''));

            if ($code === '') {
                continue;
            }

            $lookup = $pdo->prepare("SELECT id FROM {$table} WHERE code = ?");
            $lookup->execute([$code]);
            $found = $lookup->fetchColumn();

            if ($found === false) {
                json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
            }

            $sets[] = "{$column} = :{$column}";
            $params[$column] = (int) $found;
        }

        $gameId = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);

        if ($gameId) {
            $sets[] = 'game_id = :game_id';
            $params['game_id'] = $gameId;
        }

        foreach (['expires_at' => 'expires', 'region' => 'region', 'notes' => 'notes'] as $field => $bind) {
            if (!array_key_exists($field, $_POST)) {
                continue;
            }

            $sets[] = "{$field} = :{$bind}";
            $params[$bind] = trim((string) $_POST[$field]) ?: null;
        }

        $stmt = $pdo->prepare(
            'UPDATE game_keys SET ' . implode(', ', $sets) . ' WHERE id = :id AND user_id = :user'
        );

        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                json_response(['ok' => false, 'error' => __('ui.message.duplicate')], 409);
            }

            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        if ($newCode !== '') {
            Giveaways::refreshKey((int) Auth::id(), $id, $newCode);
        }

        json_response([
            'ok'      => true,
            'message' => __('ui.message.saved'),
            'labels'  => [
                'key_type'     => code_label('key_type', $keyType),
                'content_type' => code_label('content_type', $contentType),
                'status'       => code_label('key_status', $status),
            ],
        ]);
    }

    /**
     * AJAX: several key codes at once, for the global reveal toggle.
     *
     * Bounded to the ids the caller asks for and filtered by user_id, so it
     * is the same authorisation as reveal() — just one round trip instead of
     * one per row. Codes are still never rendered into the page by the
     * server; this is the only way they reach the browser.
     */
    public static function revealBulk(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);
        self::requireVault(json: true);

        $raw = (string) ($_POST['ids'] ?? '');

        $ids = array_values(array_filter(
            array_map('intval', explode(',', $raw)),
            static fn (int $id): bool => $id > 0
        ));

        if ($ids === []) {
            json_response(['ok' => true, 'keys' => []]);
        }

        $ids = array_slice(array_unique($ids), 0, 500);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = Database::connection()->prepare(
            "SELECT id, key_code FROM game_keys
              WHERE user_id = ? AND id IN ({$placeholders})"
        );
        $stmt->execute([Auth::id(), ...$ids]);

        $keys       = [];
        $unreadable = [];
        $userId     = (int) Auth::id();

        foreach ($stmt->fetchAll() as $row) {
            $plain = Vault::decryptCode($userId, (string) $row['key_code']);

            if ($plain === null) {
                $unreadable[] = (string) $row['id'];
                continue;
            }

            $keys[(string) $row['id']] = $plain;
        }

        json_response([
            'ok'         => true,
            'keys'       => $keys,
            'unreadable' => $unreadable,
            'lost_label' => $unreadable === [] ? null : __('ui.message.key_unreadable'),
        ]);
    }

    /**
     * AJAX: everything about one key, for the detail modal.
     *
     * The code itself is deliberately absent — peek and copy on the row are
     * the only paths to it, so opening details on stream stays safe.
     */
    public static function show(): void
    {
        Auth::requireLogin();

        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if ($id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT k.id, k.key_type, k.content_type, k.status, k.region,
                    k.received_at, k.activated_at, k.expires_at,
                    k.source_note, k.notes, k.created_at, k.updated_at,
                    g.id AS game_id, g.title AS game_title,
                    g.release_date, g.release_precision, g.store_url,
                    gp.code AS game_platform_code, kp.code AS key_platform_code,
                    p.name AS publisher_name, d.name AS developer_name,
                    n.id AS negotiation_id, n.subject AS negotiation_subject, n.status AS negotiation_status,
                    emb.lifts_at AS embargo_until, emb.kind AS embargo_kind, emb.label AS embargo_label,
                    (emb.lifts_at IS NOT NULL AND emb.lifts_at > now()) AS under_embargo
               FROM game_keys k
               JOIN games g               ON g.id  = k.game_id
               JOIN game_platforms gp     ON gp.id = k.game_platform_id
               JOIN key_platforms kp      ON kp.id = k.key_platform_id
          LEFT JOIN publishers p          ON p.id  = g.publisher_id
          LEFT JOIN developers d          ON d.id  = g.developer_id
          LEFT JOIN negotiations n        ON n.id  = k.negotiation_id AND n.user_id = k.user_id
          LEFT JOIN LATERAL (
                  SELECT e.lifts_at, e.kind, e.label FROM game_embargoes e
                   WHERE e.game_id = k.game_id AND e.user_id = k.user_id
                     AND (e.game_platform_id IS NULL OR e.game_platform_id = k.game_platform_id)
                ORDER BY e.lifts_at DESC LIMIT 1
              ) emb ON true
              WHERE k.id = ? AND k.user_id = ?'
        );
        $stmt->execute([$id, Auth::id()]);
        $key = $stmt->fetch();

        if ($key === false) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        $stmt = $pdo->prepare(
            'SELECT s.id, s.title, s.status, s.scheduled_start
               FROM stream_games sg
               JOIN streams s ON s.id = sg.stream_id
              WHERE sg.game_key_id = ? AND s.user_id = ?
           ORDER BY s.scheduled_start NULLS LAST'
        );
        $stmt->execute([$id, Auth::id()]);
        $usedIn = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT gv.title, w.twitch_login AS winner_handle, w.created_at AS won_at, gp.claimed_at AS delivered_at
               FROM giveaway_prizes gp
               JOIN giveaways gv ON gv.id = gp.giveaway_id
          LEFT JOIN giveaway_winners w ON w.id = gp.winner_id
              WHERE gp.game_key_id = ? AND gv.user_id = ?'
        );
        $stmt->execute([$id, Auth::id()]);
        $prize = $stmt->fetch();

        $precision = (string) $key['release_precision'];

        json_response([
            'ok'  => true,
            'key' => [
                'game'         => $key['game_title'],
                'images'       => (object) GameImages::urls($pdo, (int) $key['game_id']),
                'publisher'    => $key['publisher_name'],
                'developer'    => $key['developer_name'],
                'store_url'    => safe_url($key['store_url']),
                'release'      => $key['release_date'] === null
                    ? null
                    : ($precision === 'day'
                        ? fmt_date($key['release_date'])
                        : substr((string) $key['release_date'], 0, 4) . ' (' . code_label('release_precision', $precision) . ')'),
                'platform'     => code_label('game_platform', $key['game_platform_code']),
                'source'       => code_label('key_platform', $key['key_platform_code']),
                'key_type'     => code_label('key_type', $key['key_type']),
                'content_type' => code_label('content_type', $key['content_type']),
                'status'       => code_label('key_status', $key['status']),
                'region'       => $key['region'],
                'received'     => $key['received_at']  ? fmt_datetime($key['received_at'])  : null,
                'activated'    => $key['activated_at'] ? fmt_datetime($key['activated_at']) : null,
                'expires'      => $key['expires_at']   ? fmt_datetime($key['expires_at'])   : null,
                'provenance'   => $key['source_note'],
                'notes'        => $key['notes'],
                'created'      => fmt_datetime($key['created_at']),
                'updated'      => fmt_datetime($key['updated_at']),
            ],
            'embargo' => $key['embargo_until'] === null ? null : [
                'lifts'  => fmt_datetime($key['embargo_until']),
                'kind'   => code_label('embargo_kind', $key['embargo_kind']),
                'label'  => $key['embargo_label'],
                'active' => (bool) $key['under_embargo'],
            ],
            'negotiation' => $key['negotiation_id'] === null ? null : [
                'subject' => $key['negotiation_subject'],
                'status'  => code_label('negotiation_status', $key['negotiation_status']),
            ],
            'used_in' => array_map(static fn (array $c): array => [
                'title'     => $c['title'],
                'status'    => code_label('stream_status', $c['status']),
                'scheduled' => $c['scheduled_start'] ? fmt_datetime($c['scheduled_start']) : null,
            ], $usedIn),
            'prize' => $prize === false ? null : [
                'giveaway'  => $prize['title'],
                'winner'    => $prize['winner_handle'],
                'won'       => $prize['won_at']       ? fmt_datetime($prize['won_at'])       : null,
                'delivered' => $prize['delivered_at'] ? fmt_datetime($prize['delivered_at']) : null,
            ],
        ]);
    }

    /**
     * AJAX: delete one key.
     *
     * A key that was awarded as a giveaway prize is protected by a
     * RESTRICT foreign key, so the database refuses rather than silently
     * orphaning the prize record. That refusal is reported as a reason,
     * not as a server error.
     */
    public static function delete(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $stmt = Database::connection()->prepare('DELETE FROM game_keys WHERE id = ? AND user_id = ?');

        try {
            $stmt->execute([$id, Auth::id()]);
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['23503', '23001'], true)) {
                json_response(['ok' => false, 'error' => __('ui.message.delete_blocked_key')], 409);
            }

            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        json_response(['ok' => true, 'message' => __('ui.message.deleted')]);
    }
}
