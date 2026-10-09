<?php
declare(strict_types=1);

final class AdminController
{
    /** GET /admin — what needs attention, how StreamOrg is used, the newest accounts and additions, and every admin page by subject. */
    public static function index(): void
    {
        Auth::requireAdmin();

        View::render('admin/index', [
            'attention' => AdminOverview::attention(),
            'activity'  => AdminOverview::activity(),
            'accounts'  => AdminOverview::newestAccounts(),
            'latest'    => AdminOverview::latest(),
            'providers' => Providers::all(),
        ], __('ui.nav.admin'));
    }

    /** Units the session lifetime can be entered in, as minutes per unit. */
    private const LIFETIME_UNITS = ['days' => 1440, 'hours' => 60, 'minutes' => 1];

    public static function settings(): void
    {
        Auth::requireAdmin();

        $minutes = UserSessions::idleMinutes();

        $unit = 'minutes';

        foreach (self::LIFETIME_UNITS as $name => $size) {
            if ($minutes % $size === 0) {
                $unit = $name;
                break;
            }
        }

        View::render('admin/settings', [
            'lifetimeValue' => intdiv($minutes, self::LIFETIME_UNITS[$unit]),
            'lifetimeUnit'  => $unit,
            'units'         => array_keys(self::LIFETIME_UNITS),
            'minMinutes'    => UserSessions::IDLE_MIN,
            'maxDays'       => intdiv(UserSessions::IDLE_MAX, 1440),
            'domains'       => array_map('strval', (array) Config::get('app.domain_locales', [])),
            'fixedDomains'  => fixed_locale_domains(),
        ], __('ui.nav.settings'));
    }

    public static function saveSettings(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $value = filter_input(INPUT_POST, 'session_lifetime', FILTER_VALIDATE_INT);
        $unit  = (string) ($_POST['session_lifetime_unit'] ?? '');

        if ($value === false || $value === null || !isset(self::LIFETIME_UNITS[$unit])) {
            flash('error', __('ui.message.invalid_input'));
            redirect('/admin/settings');
        }

        $minutes = $value * self::LIFETIME_UNITS[$unit];

        if ($minutes < UserSessions::IDLE_MIN || $minutes > UserSessions::IDLE_MAX) {
            flash('error', sprintf(__('ui.message.session_lifetime_range'),
                UserSessions::IDLE_MIN, intdiv(UserSessions::IDLE_MAX, 1440)));
            redirect('/admin/settings');
        }

        Settings::set('session_idle_minutes', (string) $minutes, Auth::id());

        flash('success', __('ui.message.saved'));
        redirect('/admin/settings');
    }

    /** POST /admin/settings/domains — which domains keep their language, with no switch. */
    public static function saveDomains(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $known = array_keys((array) Config::get('app.domain_locales', []));
        $fixed = array_values(array_intersect($known, array_map('strval', (array) ($_POST['fixed'] ?? []))));

        Settings::set('fixed_locale_domains', implode(',', $fixed), Auth::id());

        flash('success', __('ui.message.saved'));
        redirect('/admin/settings');
    }

    public static function testimonials(): void
    {
        Auth::requireAdmin();

        $items = Testimonials::all();

        View::render('admin/testimonials', [
            'json'    => (string) ($_SESSION['testimonials_draft'] ?? Testimonials::json()),
            'items'   => Testimonials::forAdmin(Lang::locale()),
            'count'   => count($items),
            'shown'   => count(array_filter($items, static fn (array $t): bool => ($t['active'] ?? true) !== false)),
            'enabled' => Testimonials::isEnabled(),
        ], __('ui.nav.testimonials'));

        unset($_SESSION['testimonials_draft']);
    }

    /** Saves the testimonials from an uploaded .json file, or else the editor. */
    public static function saveTestimonials(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $file = $_FILES['file'] ?? null;
        $json = (string) ($_POST['json'] ?? '');

        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 256 * 1024 || !is_uploaded_file($file['tmp_name'])) {
                flash('error', __('ui.message.testimonials_upload_failed'));
                redirect('/admin/testimonials');
            }

            $json = (string) file_get_contents($file['tmp_name']);
        }

        try {
            $items = Testimonials::parse($json);
        } catch (UserError $e) {
            $_SESSION['testimonials_draft'] = $json;
            flash('error', $e->getMessage());
            redirect('/admin/testimonials');
        }

        Testimonials::save($items, Auth::id());

        flash('success', sprintf(__('ui.message.testimonials_saved'), count($items)));
        redirect('/admin/testimonials');
    }

    /** Shows or hides one testimonial on the landing page. */
    public static function toggleTestimonial(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $index  = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
        $active = ($_POST['active'] ?? '') === '1';
        $name   = $index === false || $index === null ? null : Testimonials::setActive($index, $active, Auth::id());

        if ($name === null) {
            flash('error', __('ui.message.not_found'));
        } else {
            flash('success', sprintf(__($active ? 'ui.message.testimonial_shown' : 'ui.message.testimonial_hidden'), $name));
        }

        redirect('/admin/testimonials');
    }

    /** Shows or hides the whole testimonials section. */
    public static function toggleTestimonialsSection(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $enabled = ($_POST['enabled'] ?? '') === '1';
        Testimonials::setEnabled($enabled, Auth::id());

        flash('success', __($enabled ? 'ui.message.testimonials_section_on' : 'ui.message.testimonials_section_off'));
        redirect('/admin/testimonials');
    }

    public static function downloadTestimonials(): void
    {
        Auth::requireAdmin();

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="testimonials.json"');
        echo Testimonials::json();
        exit;
    }

    public static function games(): void
    {
        Auth::requireAdmin();

        $pdo = Database::connection();

        View::render('admin/games', [
            'twitchReady' => Twitch::isConfigured(),
            'missing'     => TwitchCategories::defaults($pdo),
            'games' => $pdo->query(
                'SELECT g.*, p.name AS publisher_name, d.name AS developer_name
                   FROM games g
              LEFT JOIN publishers p ON p.id = g.publisher_id
              LEFT JOIN developers d ON d.id = g.developer_id
               ORDER BY g.title'
            )->fetchAll(),
        ], __('ui.nav.games'));
    }

    /**
     * Adds a game. Twitch first: a category alone adds the game with its
     * details filled in from Steam or IGDB; typed details make a game by
     * hand, with the chosen category or one looked up.
     */
    public static function storeGame(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $title      = trim((string) ($_POST['title'] ?? ''));
        $categoryId = trim((string) ($_POST['category_id'] ?? ''));

        if ($categoryId !== '' && $title === '') {
            try {
                $result = Database::transaction(static fn (PDO $pdo): array => GameCatalog::addFromTwitch($pdo, $categoryId));
            } catch (UserError $e) {
                flash('error', $e->getMessage());
                redirect('/admin/games');
            }

            flash('success', $result['created']
                ? sprintf(__('ui.message.game_added_from_twitch'), $result['title'])
                : sprintf(__('ui.message.game_already_added'), $result['title']));
            redirect('/admin/games');
        }

        $category = $categoryId !== '' ? Twitch::category($categoryId) : null;

        if ($title === '' || ($categoryId !== '' && $category === null)) {
            flash('error', __('ui.message.invalid_input'));
            redirect('/admin/games');
        }

        $release = trim((string) ($_POST['release_date'] ?? ''));

        $stmt = Database::connection()->prepare(
            'INSERT INTO games (title, slug, publisher_id, developer_id, release_date, description, store_url)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (slug) DO NOTHING
             RETURNING id'
        );
        $stmt->execute([
            $title,
            self::slug($title),
            filter_input(INPUT_POST, 'publisher_id', FILTER_VALIDATE_INT) ?: null,
            filter_input(INPUT_POST, 'developer_id', FILTER_VALIDATE_INT) ?: null,
            $release !== '' ? $release : null,
            trim((string) ($_POST['description'] ?? '')) ?: null,
            safe_url($_POST['store_url'] ?? null),
        ]);

        $gameId = $stmt->fetchColumn();

        if ($gameId !== false && $category !== null) {
            TwitchCategories::store(Database::connection(), (int) $gameId, $category, 'manual');
        } elseif ($gameId !== false) {
            TwitchCategories::assign(Database::connection(), (int) $gameId);
        }

        flash(
            $gameId !== false ? 'success' : 'error',
            $gameId !== false ? __('ui.message.saved') : __('ui.message.duplicate')
        );

        redirect('/admin/games');
    }

    /**
     * Sets a game's Twitch category by hand, from the games table. Left
     * empty, or with "find automatically", the game goes back to the Just
     * Chatting stand-in and is looked up again.
     */
    public static function gameCategory(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $gameId = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT) ?: 0;
        $raw    = trim((string) ($_POST['category_id'] ?? ''));
        $pdo    = Database::connection();

        if (($_POST['find'] ?? '') === '1') {
            TwitchCategories::store($pdo, $gameId, null);
            $category = TwitchCategories::assign($pdo, $gameId);

            flash($category ? 'success' : 'error', $category
                ? sprintf(__('ui.message.twitch_category_found'), $category['name'])
                : __('ui.message.twitch_category_not_found'));
            redirect('/admin/games');
        }

        $category = $raw === '' ? null : Twitch::category($raw);

        if ($raw !== '' && $category === null) {
            flash('error', __('ui.message.invalid_input'));
            redirect('/admin/games');
        }

        TwitchCategories::store($pdo, $gameId, $category, 'manual');

        flash('success', __('ui.message.saved'));
        redirect('/admin/games');
    }

    /** Looks up the next batch of games that only have the Just Chatting stand-in. */
    public static function findCategories(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        if (!Twitch::isConfigured()) {
            flash('error', __('ui.message.twitch_not_configured'));
            redirect('/admin/games');
        }

        $result = TwitchCategories::fillMissing(Database::connection());

        flash('success', sprintf(__('ui.message.twitch_categories_filled'), $result['found'], $result['checked'])
            . ($result['left'] > 0 ? ' ' . sprintf(__('ui.message.twitch_categories_left'), $result['left']) : ''));
        redirect('/admin/games');
    }

    public static function publishers(): void
    {
        Auth::requireAdmin();

        View::render('admin/companies', [
            'kind' => 'publisher',
            'rows' => Database::connection()->query(
                'SELECT p.*, (SELECT count(*) FROM games g WHERE g.publisher_id = p.id) AS game_count
                   FROM publishers p ORDER BY p.name'
            )->fetchAll(),
        ], __('ui.nav.publishers'));
    }

    public static function developers(): void
    {
        Auth::requireAdmin();

        View::render('admin/companies', [
            'kind' => 'developer',
            'rows' => Database::connection()->query(
                'SELECT d.*, (SELECT count(*) FROM games g WHERE g.developer_id = d.id) AS game_count
                   FROM developers d ORDER BY d.name'
            )->fetchAll(),
        ], __('ui.nav.developers'));
    }

    public static function storePublisher(): void
    {
        self::storeCompany('publishers', '/admin/publishers');
    }

    public static function storeDeveloper(): void
    {
        self::storeCompany('developers', '/admin/developers');
    }

    private static function storeCompany(string $table, string $back): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            flash('error', __('ui.message.invalid_input'));
            redirect($back);
        }

        $stmt = Database::connection()->prepare(
            "INSERT INTO {$table} (name, slug, website, country_code)
             VALUES (?, ?, ?, ?) ON CONFLICT (slug) DO NOTHING"
        );
        $stmt->execute([
            $name,
            self::slug($name),
            safe_url($_POST['website'] ?? null),
            strtoupper(trim((string) ($_POST['country_code'] ?? ''))) ?: null,
        ]);

        flash(
            $stmt->rowCount() > 0 ? 'success' : 'error',
            $stmt->rowCount() > 0 ? __('ui.message.saved') : __('ui.message.duplicate')
        );

        redirect($back);
    }

    public static function keySites(): void
    {
        Auth::requireAdmin();

        View::render('admin/key_sites', [
            'rows' => Database::connection()->query(
                'SELECT kp.*, (SELECT count(*) FROM game_keys k WHERE k.key_platform_id = kp.id) AS key_count
                   FROM key_platforms kp ORDER BY kp.sort_order, kp.code'
            )->fetchAll(),
        ], __('ui.nav.key_sites'));
    }

    public static function storeKeySite(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $code = strtolower(trim((string) ($_POST['code'] ?? '')));

        if (!preg_match('/^[a-z0-9_]{2,40}$/', $code)) {
            flash('error', __('ui.message.invalid_code'));
            redirect('/admin/key-sites');
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO key_platforms (code, website, sort_order, tags_content)
             VALUES (:code, :website, :sort, :tags) ON CONFLICT (code) DO NOTHING'
        );
        $stmt->bindValue('code', $code);
        $stmt->bindValue('website', safe_url($_POST['website'] ?? null));
        $stmt->bindValue('sort', filter_input(INPUT_POST, 'sort_order', FILTER_VALIDATE_INT) ?: 0, PDO::PARAM_INT);
        $stmt->bindValue('tags', !empty($_POST['tags_content']), PDO::PARAM_BOOL);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $labelled = 0;

            foreach ((array) ($_POST['labels'] ?? []) as $locale => $label) {
                if (in_array($locale, Lang::available(), true) && is_string($label) && trim($label) !== '') {
                    CodeLabels::set('key_platform', $code, $locale, $label, Auth::id());
                    $labelled++;
                }
            }

            flash('success', __($labelled > 0 ? 'ui.message.saved' : 'ui.message.code_added_needs_label'));
        } else {
            flash('error', __('ui.message.duplicate'));
        }

        redirect('/admin/key-sites');
    }

    public static function apiSettings(): void
    {
        Auth::requireAdmin();

        $rows = Database::connection()->query('SELECT * FROM api_settings ORDER BY provider')->fetchAll();
        $settings = array_column($rows, null, 'provider');

        $hints = [];

        foreach ($settings as $code => $row) {
            foreach (['api_key', 'client_id', 'client_secret'] as $field) {
                $hints[$code][$field] = Crypto::hint(Crypto::decrypt($row[$field] ?? null));
            }
        }

        View::render('admin/api', [
            'settings'   => $settings,
            'hints'      => $hints,
            'providers'  => Providers::all(),
            'cryptoReady' => Crypto::isReady(),
        ], __('ui.nav.api_settings'));
    }

    public static function saveApiSettings(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $provider = (string) ($_POST['provider'] ?? '');

        if ($provider !== 'twitch' && Providers::get($provider) === null) {
            flash('error', __('ui.message.not_found'));
            redirect('/admin/api');
        }

        if (!Crypto::isReady()) {
            flash('error', __('ui.message.no_app_key'));
            redirect('/admin/api');
        }

        $pdo = Database::connection();

        $sets   = ['is_enabled = :enabled'];
        $params = [];

        foreach (['api_key', 'client_id', 'client_secret'] as $field) {
            $value = trim((string) ($_POST[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            $sets[] = "{$field} = :{$field}";
            $params[$field] = Crypto::encrypt($value);
        }

        if (!empty($_POST['clear_credentials'])) {
            $sets = ['is_enabled = :enabled', 'api_key = NULL', 'client_id = NULL', 'client_secret = NULL'];
            $params = [];
        }

        $stmt = $pdo->prepare(
            'UPDATE api_settings SET ' . implode(', ', $sets) . ' WHERE provider = :provider'
        );

        $stmt->bindValue('enabled', !empty($_POST['is_enabled']), PDO::PARAM_BOOL);
        $stmt->bindValue('provider', $provider);

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }

        $stmt->execute();
        Twitch::forget();

        if (!empty($_POST['is_default'])) {
            $pdo = Database::connection();
            $pdo->exec('UPDATE api_settings SET is_default = false WHERE is_default');
            $stmt = $pdo->prepare('UPDATE api_settings SET is_default = true WHERE provider = ?');
            $stmt->execute([$provider]);
        }

        Providers::forget();
        flash('success', __('ui.message.saved'));
        redirect('/admin/api');
    }

    /** AJAX: live check of one provider's credentials. */
    public static function testProvider(): void
    {
        Auth::requireAdmin();
        Csrf::verify(json: true);

        $code = (string) ($_POST['provider'] ?? '');

        if ($code === 'twitch') {
            $result = Twitch::test();
        } else {
            $provider = Providers::get($code);

            if ($provider === null) {
                json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
            }

            $result = $provider->test();
        }

        $stmt = Database::connection()->prepare(
            'UPDATE api_settings
                SET last_tested_at = now(), last_test_ok = :ok, last_test_note = :note
              WHERE provider = :provider'
        );
        $stmt->bindValue('ok', $result['ok'], PDO::PARAM_BOOL);
        $stmt->bindValue('note', $result['note']);
        $stmt->bindValue('provider', $code);
        $stmt->execute();

        json_response([
            'ok'   => $result['ok'],
            'note' => $result['note'],
            'at'   => fmt_datetime(date('c')),
        ]);
    }

    /** ASCII slug; falls back to a hash when a title has no latin characters. */
    public static function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);

        if (is_string($transliterated)) {
            $slug = $transliterated;
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : substr(hash('sha256', $value), 0, 16);
    }

    /**
     * AJAX: delete a shared-catalogue row.
     *
     * The table is chosen from a fixed map, never from the request, so the
     * parameter cannot reach the SQL. Rows still referenced elsewhere are
     * protected by RESTRICT foreign keys and report why.
     */
    public static function deleteCatalogue(): void
    {
        Auth::requireAdmin();
        Csrf::verify(json: true);

        $tables = [
            'game'      => ['games', 'ui.message.delete_blocked_game'],
            'publisher' => ['publishers', 'ui.message.delete_blocked_generic'],
            'developer' => ['developers', 'ui.message.delete_blocked_generic'],
            'key_site'  => ['key_platforms', 'ui.message.delete_blocked_key_site'],
        ];

        $kind = (string) ($_POST['kind'] ?? '');
        $id   = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if (!isset($tables[$kind]) || $id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        [$table, $blockedKey] = $tables[$kind];

        $stmt = Database::connection()->prepare("DELETE FROM {$table} WHERE id = ?");

        try {
            $stmt->execute([$id]);
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['23503', '23001'], true)) {
                json_response(['ok' => false, 'error' => __($blockedKey)], 409);
            }

            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        if ($kind === 'game') {
            GameImages::deleteFiles($id);
        }

        json_response(['ok' => true, 'message' => __('ui.message.deleted')]);
    }

    /** AJAX: edit a key site. Key sites had no edit path at all before. */
    public static function updateKeySite(): void
    {
        Auth::requireAdmin();
        Csrf::verify(json: true);

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($id === false || $id === null) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 400);
        }

        $stmt = Database::connection()->prepare(
            'UPDATE key_platforms
                SET website = :website, sort_order = :sort, tags_content = :tags
              WHERE id = :id'
        );
        $stmt->bindValue('website', safe_url($_POST['website'] ?? null));
        $stmt->bindValue('sort', filter_input(INPUT_POST, 'sort_order', FILTER_VALIDATE_INT) ?: 0, PDO::PARAM_INT);
        $stmt->bindValue('tags', !empty($_POST['tags_content']), PDO::PARAM_BOOL);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'error' => __('ui.message.not_found')], 404);
        }

        json_response(['ok' => true, 'message' => __('ui.message.saved')]);
    }
}
