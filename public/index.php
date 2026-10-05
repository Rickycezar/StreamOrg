<?php
declare(strict_types=1);

/**
 * Front controller. Every request enters here.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$config = Config::load();

$debug = !empty($config['app']['debug']) && ($config['app']['env'] ?? 'production') !== 'production';

if ($debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

send_security_headers();

$requestPath = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$hasSession  = isset($_COOKIE[session_name()]);

if ($requestPath === '/healthz') {
    HealthController::index();
}

if ($requestPath === '/twitch/eventsub' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    TwitchEventController::receive();
}

if ($hasSession || !in_array($requestPath, ['/', '/language'], true)) {
    Auth::start();
}

Lang::setLocale(Lang::resolve(
    Auth::user()['locale'] ?? null,
    isset($_COOKIE[Lang::COOKIE]) ? (string) $_COOKIE[Lang::COOKIE] : null,
    (string) ($_SERVER['HTTP_HOST'] ?? ''),
    (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
    (array) Config::get('app.domain_locales', []),
    (string) Config::get('app.locale', 'en'),
));

if (($user = Auth::user()) !== null) {
    date_default_timezone_set((string) ($user['timezone'] ?: Config::get('app.timezone', 'UTC')));
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim((string) Config::get('app.base_path', ''), '/');

if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}

$path   = '/' . trim($path, '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$routes = [
    'GET' => [
        '/'                  => [LandingController::class, 'index'],
        '/healthz'           => [HealthController::class, 'index'],
        '/language'          => [LocaleController::class, 'switch'],
        '/dashboard'         => [DashboardController::class, 'index'],
        '/login'             => [AuthController::class, 'showLogin'],
        '/keys'              => [KeysController::class, 'index'],
        '/vault/security'    => [VaultInfoController::class, 'security'],
        '/negotiations'      => [NegotiationController::class, 'index'],
        '/content'           => [ContentController::class, 'index'],
        '/catalog'           => [CatalogController::class, 'index'],
        '/profile'           => [ProfileController::class, 'index'],
        '/profile/password'  => [ProfileController::class, 'passwordPage'],
        '/profile/appearance' => [ProfileController::class, 'appearance'],
        '/profile/defaults'  => [ProfileController::class, 'defaults'],
        '/profile/security'  => [ProfileController::class, 'security'],
        '/profile/twitch/connect'  => [ProfileController::class, 'twitchConnect'],
        '/profile/twitch/callback' => [ProfileController::class, 'twitchCallback'],
        '/content/twitch'    => [ContentController::class, 'twitchPreview'],
        '/catalog/search'    => [CatalogController::class, 'search'],
        '/pickers/games'     => [PickerController::class, 'games'],
        '/pickers/companies' => [PickerController::class, 'companies'],
        '/embargoes'         => [EmbargoController::class, 'index'],
        '/streamers'         => [StreamerController::class, 'index'],
        '/streamers/search'  => [StreamerController::class, 'search'],
        '/collabs'           => [CollabController::class, 'index'],
        '/admin'             => [AdminController::class, 'index'],
        '/admin/games'       => [AdminController::class, 'games'],
        '/admin/publishers'  => [AdminController::class, 'publishers'],
        '/admin/developers'  => [AdminController::class, 'developers'],
        '/admin/key-sites'   => [AdminController::class, 'keySites'],
        '/admin/api'         => [AdminController::class, 'apiSettings'],
        '/admin/settings'    => [AdminController::class, 'settings'],
        '/admin/testimonials' => [AdminController::class, 'testimonials'],
        '/admin/users'       => [UserAdminController::class, 'index'],
        '/admin/testimonials/download' => [AdminController::class, 'downloadTestimonials'],
        '/admin/lang'        => [LangController::class, 'index'],
        '/admin/import'      => [ImportController::class, 'index'],
        '/admin/import/search' => [ImportController::class, 'search'],
        '/content/show'      => [ContentController::class, 'show'],
        '/content/calendar'  => [ContentController::class, 'calendar'],
        '/keys/show'         => [KeysController::class, 'show'],
        '/keys/edit'         => [KeysController::class, 'editForm'],
    ],
    'POST' => [
        '/login'             => [AuthController::class, 'login'],
        '/logout'            => [AuthController::class, 'logout'],
        '/keys'              => [KeysController::class, 'store'],
        '/keys/reveal'       => [KeysController::class, 'reveal'],
        '/keys/status'       => [KeysController::class, 'updateStatus'],
        '/keys/quick'        => [KeysController::class, 'quickStore'],
        '/content'           => [ContentController::class, 'store'],
        '/content/status'    => [ContentController::class, 'updateStatus'],
        '/content/schedule'  => [ContentController::class, 'schedule'],
        '/content/update'    => [ContentController::class, 'update'],
        '/keys/update'       => [KeysController::class, 'update'],
        '/keys/reveal-bulk'  => [KeysController::class, 'revealBulk'],
        '/keys/delete'       => [KeysController::class, 'delete'],
        '/content/delete'    => [ContentController::class, 'delete'],
        '/admin/catalogue/delete' => [AdminController::class, 'deleteCatalogue'],
        '/admin/key-sites/update' => [AdminController::class, 'updateKeySite'],
        '/catalog/import'    => [CatalogController::class, 'import'],
        '/catalog/refresh'   => [CatalogController::class, 'refresh'],
        '/embargoes'         => [EmbargoController::class, 'store'],
        '/embargoes/update'  => [EmbargoController::class, 'update'],
        '/embargoes/delete'  => [EmbargoController::class, 'delete'],
        '/preferences'       => [PreferenceController::class, 'save'],
        '/profile'           => [ProfileController::class, 'update'],
        '/profile/password'  => [ProfileController::class, 'password'],
        '/profile/vault'     => [ProfileController::class, 'vault'],
        '/profile/appearance' => [ProfileController::class, 'theme'],
        '/profile/defaults'  => [ProfileController::class, 'saveDefaults'],
        '/profile/sessions/revoke'        => [ProfileController::class, 'revokeSession'],
        '/profile/sessions/revoke-others' => [ProfileController::class, 'revokeOtherSessions'],
        '/profile/twitch/disconnect'      => [ProfileController::class, 'twitchDisconnect'],
        '/profile/twitch/tracking'        => [ProfileController::class, 'twitchTracking'],
        '/content/twitch'    => [ContentController::class, 'twitchPush'],
        '/vault/unlock'      => [ProfileController::class, 'unlockVault'],
        '/streamers'         => [StreamerController::class, 'store'],
        '/streamers/update'  => [StreamerController::class, 'update'],
        '/streamers/delete'  => [StreamerController::class, 'delete'],
        '/streamers/import'  => [StreamerController::class, 'import'],
        '/collabs'           => [CollabController::class, 'store'],
        '/collabs/update'    => [CollabController::class, 'update'],
        '/collabs/delete'    => [CollabController::class, 'delete'],
        '/collabs/plan'      => [CollabController::class, 'plan'],
        '/admin/games'       => [AdminController::class, 'storeGame'],
        '/admin/publishers'  => [AdminController::class, 'storePublisher'],
        '/admin/developers'  => [AdminController::class, 'storeDeveloper'],
        '/admin/key-sites'   => [AdminController::class, 'storeKeySite'],
        '/admin/api'         => [AdminController::class, 'saveApiSettings'],
        '/admin/settings'    => [AdminController::class, 'saveSettings'],
        '/admin/testimonials' => [AdminController::class, 'saveTestimonials'],
        '/admin/testimonials/toggle' => [AdminController::class, 'toggleTestimonial'],
        '/admin/testimonials/section' => [AdminController::class, 'toggleTestimonialsSection'],
        '/admin/users'       => [UserAdminController::class, 'store'],
        '/admin/users/update' => [UserAdminController::class, 'update'],
        '/admin/users/password' => [UserAdminController::class, 'resetPassword'],
        '/admin/users/delete' => [UserAdminController::class, 'delete'],
        '/admin/api/test'    => [AdminController::class, 'testProvider'],
        '/admin/lang'        => [LangController::class, 'save'],
        '/admin/import/game' => [ImportController::class, 'import'],
    ],
];

$handler = $routes[$method][$path] ?? null;

if ($handler === null) {
    http_response_code(404);
    View::render('error', [
        'heading' => '404',
        'message' => __('ui.message.not_found'),
    ], '404');
    exit;
}

try {
    $handler();
} catch (Throwable $e) {
    if ($debug) {
        throw $e;
    }

    error_log('StreamOrg: ' . $e->getMessage());
    http_response_code(500);
    View::render('error', [
        'heading' => '500',
        'message' => __('ui.message.server_error'),
    ], '500');
}
