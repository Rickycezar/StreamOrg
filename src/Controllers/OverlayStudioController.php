<?php
declare(strict_types=1);

/**
 * Stream tools → Overlays: a streamer's browser sources for OBS (add one from a
 * type, copy its link, change its settings with a live preview, send a
 * test) and their media library; and Administration → Overlays, the
 * settings for everyone (on or off, which types, limits, addresses) and the
 * shared media. The overlay pages themselves are OverlayController's.
 */
final class OverlayStudioController
{
    /** GET /overlays */
    public static function index(): void
    {
        Auth::requireLogin();
        $userId = (int) Auth::id();

        View::render('stream/frame', [
            'tab'     => '/overlays',
            'tabView' => 'overlays/index',
            'tabData' => [
                'overlays' => Overlays::forUser($userId),
                'types'    => array_values(array_filter(array_keys(OverlayTypes::all()), [OverlayConfig::class, 'typeAllowed'])),
                'enabled'  => OverlayConfig::enabled(),
                'limit'    => OverlayConfig::maxPerUser(),
            ],
        ], __('ui.nav.overlays'));
    }

    /** GET /overlays/media */
    public static function media(): void
    {
        Auth::requireLogin();
        $userId = (int) Auth::id();

        View::render('stream/frame', [
            'tab'     => '/overlays/media',
            'tabView' => 'overlays/media',
            'tabData' => ['library' => self::libraryData(MediaLibrary::available($userId), false, MediaLibrary::usage($userId))],
        ], __('ui.nav.media_library'));
    }

    /** GET /overlays/edit?id= */
    public static function edit(): void
    {
        Auth::requireLogin();
        $userId  = (int) Auth::id();
        $overlay = Overlays::find(filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0, $userId);

        if ($overlay === null || OverlayTypes::get($overlay['type']) === null) {
            error_page(404);
        }

        $text     = $overlay['advanced'] ? Overlays::textFor($overlay, $userId) : '';
        $warnings = $overlay['advanced'] ? OverlayIni::parse($overlay['type'], $text, $userId)[1] : [];
        $twitch   = Database::connection()->prepare('SELECT twitch_login, twitch_user_id FROM twitch_connections WHERE user_id = ?');
        $twitch->execute([$userId]);
        $channel  = $twitch->fetch() ?: ['twitch_login' => null, 'twitch_user_id' => null];

        View::render('overlays/edit', [
            'overlay'    => $overlay,
            'definition' => OverlayTypes::get($overlay['type']),
            'media'      => MediaLibrary::available($userId),
            'typeOn'     => OverlayConfig::enabled() && OverlayConfig::typeAllowed($overlay['type']),
            'sampleUser' => Overlays::sampleUser($userId),
            'text'       => $text,
            'warnings'   => $warnings,
            'cssAllowed' => OverlayConfig::customCss(),
            'channel'    => $channel['twitch_login'] !== null ? strtolower((string) $channel['twitch_login']) : null,
            'channelId'  => $channel['twitch_user_id'],
            'botStatus'  => self::botStatus($userId),
        ], $overlay['name']);
    }

    /** POST /overlays — adds an overlay of a type, then opens its settings. */
    public static function store(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        try {
            $id = Overlays::create((int) Auth::id(), (string) ($_POST['type'] ?? ''), (string) ($_POST['name'] ?? ''));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/overlays');
        }

        flash('success', __('ui.message.overlay_created'));
        redirect('/overlays/edit?id=' . $id);
    }

    /**
     * POST /overlays/update — saves the form of the mode it was shown in
     * (simple fields, or the advanced text and CSS); with "switch_to", then
     * switches to the other mode.
     */
    public static function update(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $id       = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $userId   = (int) Auth::id();
        $advanced = !empty($_POST['advanced']);

        try {
            $warnings = Overlays::update(
                $id, $userId, (string) ($_POST['name'] ?? ''), !empty($_POST['enabled']),
                (array) ($_POST['settings'] ?? []), $advanced,
                $advanced ? (string) ($_POST['config_text'] ?? '') : null,
                $advanced && isset($_POST['custom_css']) ? (string) $_POST['custom_css'] : null
            );

            if (in_array($_POST['switch_to'] ?? '', ['simple', 'advanced'], true)) {
                Overlays::setMode($id, $userId, $_POST['switch_to'] === 'advanced');
                redirect('/overlays/edit?id=' . $id);
            }
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/overlays');
        }

        flash($warnings === [] ? 'success' : 'warning', $warnings === []
            ? __('ui.message.overlay_saved')
            : sprintf(__('ui.message.overlay_saved_warnings'), count($warnings)));
        redirect('/overlays/edit?id=' . $id);
    }

    /**
     * POST /overlays/preview — the settings the unsaved form or text gives,
     * as the overlay would receive them, with what the text had wrong
     * (JSON, for the live preview).
     */
    public static function preview(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);
        $userId  = (int) Auth::id();
        $overlay = Overlays::find(filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0, $userId);

        if ($overlay === null) {
            json_response(['ok' => false, 'error' => __('ui.message.overlay_not_found')], 404);
        }

        $advanced = !empty($_POST['advanced']);
        [$settings, $warnings] = Overlays::settingsFrom($overlay, $userId, (array) ($_POST['settings'] ?? []), $advanced, (string) ($_POST['config_text'] ?? ''));

        json_response([
            'ok'       => true,
            'settings' => OverlayTypes::resolve($overlay['type'], $settings, $userId),
            'css'      => Overlays::cssFor($advanced, Overlays::cleanCss((string) ($_POST['custom_css'] ?? ''))),
            'warnings' => $warnings,
        ]);
    }

    /** GET /overlays/lookup?id=&login= — a channel's details for the preview of a shoutout (JSON). */
    public static function lookup(): void
    {
        Auth::requireLogin();
        $overlay = Overlays::find(filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0, (int) Auth::id());
        $details = $overlay !== null ? Overlays::channelDetails((string) ($_GET['login'] ?? ''), $overlay['id']) : null;

        json_response(['ok' => $details !== null, 'channel' => $details], $details !== null ? 200 : 404);
    }

    /** POST /overlays/new-link — retires the old link. */
    public static function newLink(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;

        try {
            Overlays::newLink($id, (int) Auth::id());
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/overlays');
        }

        flash('success', __('ui.message.overlay_new_link'));
        redirect('/overlays/edit?id=' . $id);
    }

    /** POST /overlays/delete */
    public static function delete(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        Overlays::delete(filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0, (int) Auth::id());
        flash('success', __('ui.message.overlay_deleted'));
        redirect('/overlays');
    }

    /** POST /overlays/test — a test event for the open overlays (JSON). */
    public static function test(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        try {
            Overlays::test(filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0, (int) Auth::id(), (string) ($_POST['event'] ?? ''));
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        json_response(['ok' => true]);
    }

    /** GET /admin/overlays */
    public static function admin(): void
    {
        Auth::requireAdmin();

        View::render('admin/overlays', [
            'config'  => OverlayConfig::all(),
            'types'   => array_keys(OverlayTypes::all()),
            'baseUrl' => OverlayConfig::baseUrl(),
            'counts'  => Database::connection()->query(
                'SELECT type, count(*) AS total, count(*) FILTER (WHERE last_seen_at > now() - interval \'10 minutes\') AS open FROM overlays GROUP BY type'
            )->fetchAll(PDO::FETCH_UNIQUE),
            'library' => self::libraryData(MediaLibrary::shared(), true, null),
        ], __('ui.nav.overlays'));
    }

    /** POST /admin/overlays */
    public static function saveAdmin(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        try {
            OverlayConfig::save($_POST, (int) Auth::id());
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/admin/overlays');
        }

        flash('success', __('ui.message.saved'));
        redirect('/admin/overlays');
    }

    /** Whether the chat bot can send overlay replies in the user's chat: active, off (not added) or unavailable. */
    private static function botStatus(int $userId): string
    {
        if (!ChatBot::isAvailable()) {
            return 'unavailable';
        }

        $channel = ChatBot::channel($userId);

        return $channel !== null && $channel['is_enabled'] && !$channel['is_blocked'] ? 'active' : 'off';
    }

    /**
     * What the media library partial needs: the assets, whether this is
     * the shared library (administration), and the room left.
     *
     * @return array<string, mixed>
     */
    private static function libraryData(array $assets, bool $shared, ?int $usage): array
    {
        return [
            'assets'   => $assets,
            'shared'   => $shared,
            'usage'    => $usage,
            'quota'    => OverlayConfig::quotaBytes(),
            'maxSound' => OverlayConfig::maxBytes('sound'),
            'maxImage' => OverlayConfig::maxBytes('image'),
        ];
    }
}
