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

ErrorLog::install();

send_security_headers();

$requestHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));

if (str_starts_with($requestHost, 'www.') && in_array(substr($requestHost, 4), app_hosts(), true)) {
    header('Location: https://' . substr($requestHost, 4) . ($_SERVER['REQUEST_URI'] ?? '/'), true, 308);
    exit;
}

$requestPath = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$hasSession  = isset($_COOKIE[session_name()]);

if ($requestPath === '/healthz') {
    HealthController::index();
}

if ($requestPath === '/twitch/eventsub' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    TwitchEventController::receive();
}

if (str_starts_with($requestPath . '/', '/overlay/')) {
    Lang::setLocale((string) Config::get('app.locale', 'en'));
    OverlayController::dispatch($requestPath, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
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
    fixed_locale_domains(),
));
Lang::setSwitchable(Lang::fixedFor((string) ($_SERVER['HTTP_HOST'] ?? ''), (array) Config::get('app.domain_locales', []), fixed_locale_domains()) === null);

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
        '/help/dashboard'    => [HelpController::class, 'dashboard'],
        '/help/keys'         => [HelpController::class, 'keys'],
        '/help/content'      => [HelpController::class, 'content'],
        '/help/defaults'     => [HelpController::class, 'defaults'],
        '/help/giveaways'    => [HelpController::class, 'giveaways'],
        '/help/embargoes'    => [HelpController::class, 'embargoes'],
        '/help/collabs'      => [HelpController::class, 'collabs'],
        '/help/together'     => [HelpController::class, 'together'],
        '/collabs/session'   => [CollabController::class, 'session'],
        '/giveaways'         => [GiveawayController::class, 'index'],
        '/claim'             => [ViewerController::class, 'claim'],
        '/viewer/login'      => [ViewerController::class, 'login'],
        '/prizes'            => [ViewerController::class, 'prizes'],
        '/privacy'           => [ViewerController::class, 'privacy'],
        '/giveaways/show'    => [GiveawayController::class, 'show'],
        '/negotiations'      => [NegotiationController::class, 'index'],
        '/content'           => [ContentController::class, 'index'],
        '/catalog'           => [CatalogController::class, 'index'],
        '/profile'           => [ProfileController::class, 'index'],
        '/profile/password'  => [ProfileController::class, 'passwordPage'],
        '/profile/appearance' => [ProfileController::class, 'appearance'],
        '/profile/defaults'  => [ProfileController::class, 'defaults'],
        '/bot'               => [BotController::class, 'profile'],
        '/bot/commands'      => [BotController::class, 'commands'],
        '/bot/timers'        => [BotController::class, 'timers'],
        '/bot/logs'          => [BotController::class, 'logs'],
        '/profile/bot'       => [BotController::class, 'moved'],
        '/profile/bot/commands' => [BotController::class, 'moved'],
        '/profile/bot/timers' => [BotController::class, 'moved'],
        '/profile/bot/logs'  => [BotController::class, 'moved'],
        '/admin/bot'         => [BotController::class, 'admin'],
        '/admin/bot/connect' => [BotController::class, 'connect'],
        '/profile/security'  => [ProfileController::class, 'security'],
        '/profile/twitch/connect'  => [ProfileController::class, 'twitchConnect'],
        '/profile/twitch/callback' => [ProfileController::class, 'twitchCallback'],
        '/content/twitch'    => [ContentController::class, 'twitchPreview'],
        '/catalog/search'    => [CatalogController::class, 'search'],
        '/pickers/games'     => [PickerController::class, 'games'],
        '/pickers/companies' => [PickerController::class, 'companies'],
        '/pickers/twitch-categories' => [PickerController::class, 'twitchCategories'],
        '/pickers/users'     => [PickerController::class, 'users'],
        '/notifications'     => [NotificationController::class, 'index'],
        '/notifications/panel' => [NotificationController::class, 'panel'],
        '/notifications/open' => [NotificationController::class, 'open'],
        '/admin/notifications' => [NotificationController::class, 'admin'],
        '/admin/winners'     => [PrizeRemovalController::class, 'index'],
        '/admin/errors'      => [ErrorLogController::class, 'index'],
        '/admin/bugs'        => [SupportAdminController::class, 'bugs'],
        '/admin/bugs/view'   => [SupportAdminController::class, 'bug'],
        '/admin/feedback'    => [SupportAdminController::class, 'feedback'],
        '/admin/surveys'     => [SupportAdminController::class, 'surveys'],
        '/admin/surveys/edit' => [SupportAdminController::class, 'surveyEdit'],
        '/admin/surveys/results' => [SupportAdminController::class, 'surveyResults'],
        '/admin/surveys/export' => [SupportAdminController::class, 'surveyExport'],
        '/support'           => [SupportController::class, 'index'],
        '/support/bug'       => [SupportController::class, 'bugForm'],
        '/support/bug/view'  => [SupportController::class, 'bugShow'],
        '/support/image'     => [SupportController::class, 'image'],
        '/support/feedback'  => [SupportController::class, 'feedbackForm'],
        '/support/message'   => [SupportController::class, 'thread'],
        '/support/survey'    => [SupportController::class, 'survey'],
        '/embargoes'         => [EmbargoController::class, 'index'],
        '/overlays'          => [OverlayStudioController::class, 'index'],
        '/overlays/media'    => [OverlayStudioController::class, 'media'],
        '/overlays/edit'     => [OverlayStudioController::class, 'edit'],
        '/overlays/lookup'   => [OverlayStudioController::class, 'lookup'],
        '/admin/overlays'    => [OverlayStudioController::class, 'admin'],
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
        '/giveaways'         => [GiveawayController::class, 'store'],
        '/claim'             => [ViewerController::class, 'redeem'],
        '/viewer/logout'     => [ViewerController::class, 'logout'],
        '/viewer/delete'     => [ViewerController::class, 'delete'],
        '/giveaways/update'  => [GiveawayController::class, 'update'],
        '/giveaways/status'  => [GiveawayController::class, 'status'],
        '/giveaways/delete'  => [GiveawayController::class, 'delete'],
        '/giveaways/prizes'  => [GiveawayController::class, 'addPrizes'],
        '/giveaways/prizes/remove' => [GiveawayController::class, 'removePrize'],
        '/giveaways/claimable' => [GiveawayController::class, 'makeClaimable'],
        '/giveaways/take-back' => [GiveawayController::class, 'takeBack'],
        '/giveaways/nudge-later' => [GiveawayController::class, 'nudgeLater'],
        '/giveaways/keep-open' => [GiveawayController::class, 'keepOpen'],
        '/giveaways/winners' => [GiveawayController::class, 'addWinner'],
        '/giveaways/winners/draw' => [GiveawayController::class, 'draw'],
        '/giveaways/winners/cancel' => [GiveawayController::class, 'cancelWinner'],
        '/giveaways/winners/extend' => [GiveawayController::class, 'extendWinner'],
        '/keys/status'       => [KeysController::class, 'updateStatus'],
        '/keys/quick'        => [KeysController::class, 'quickStore'],
        '/content'           => [ContentController::class, 'store'],
        '/content/status'    => [ContentController::class, 'updateStatus'],
        '/content/schedule'  => [ContentController::class, 'schedule'],
        '/content/twitch-schedule' => [ContentController::class, 'twitchSchedule'],
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
        '/overlays'          => [OverlayStudioController::class, 'store'],
        '/overlays/update'   => [OverlayStudioController::class, 'update'],
        '/overlays/new-link' => [OverlayStudioController::class, 'newLink'],
        '/overlays/delete'   => [OverlayStudioController::class, 'delete'],
        '/overlays/test'     => [OverlayStudioController::class, 'test'],
        '/overlays/preview'  => [OverlayStudioController::class, 'preview'],
        '/admin/overlays'    => [OverlayStudioController::class, 'saveAdmin'],
        '/media-library'     => [MediaController::class, 'upload'],
        '/media-library/update' => [MediaController::class, 'update'],
        '/media-library/delete' => [MediaController::class, 'delete'],
        '/preferences'       => [PreferenceController::class, 'save'],
        '/profile'           => [ProfileController::class, 'update'],
        '/profile/password'  => [ProfileController::class, 'password'],
        '/profile/vault'     => [ProfileController::class, 'vault'],
        '/profile/appearance' => [ProfileController::class, 'theme'],
        '/profile/theme-mode' => [ProfileController::class, 'themeMode'],
        '/profile/avatar'    => [ProfileController::class, 'avatar'],
        '/profile/avatar/twitch' => [ProfileController::class, 'avatarFromTwitch'],
        '/profile/avatar/remove' => [ProfileController::class, 'avatarRemove'],
        '/profile/defaults'  => [ProfileController::class, 'saveDefaults'],
        '/profile/defaults/content' => [ProfileController::class, 'saveContentDefaults'],
        '/bot'       => [BotController::class, 'toggleChannel'],
        '/bot/command' => [BotController::class, 'personalCommand'],
        '/bot/recheck' => [BotController::class, 'recheck'],
        '/bot/custom' => [BotController::class, 'saveCustom'],
        '/bot/custom/delete' => [BotController::class, 'deleteCustom'],
        '/bot/timer' => [BotController::class, 'saveTimer'],
        '/bot/timer/delete' => [BotController::class, 'deleteTimer'],
        '/admin/bot/settings' => [BotController::class, 'settings'],
        '/admin/bot/command' => [BotController::class, 'defaultCommand'],
        '/admin/bot/channel' => [BotController::class, 'block'],
        '/admin/bot/add'     => [BotController::class, 'adminChannel'],
        '/notifications/read-all' => [NotificationController::class, 'readAll'],
        '/collabs/together'  => [CollabController::class, 'together'],
        '/collabs/session/respond' => [CollabController::class, 'respond'],
        '/collabs/session/propose' => [CollabController::class, 'propose'],
        '/collabs/session/answer' => [CollabController::class, 'answer'],
        '/collabs/session/leave' => [CollabController::class, 'leave'],
        '/admin/notifications' => [NotificationController::class, 'send'],
        '/admin/notifications/delete' => [NotificationController::class, 'delete'],
        '/admin/winners/remove' => [PrizeRemovalController::class, 'remove'],
        '/admin/errors/resolve' => [ErrorLogController::class, 'resolve'],
        '/admin/errors/clear' => [ErrorLogController::class, 'clear'],
        '/admin/bugs/update' => [SupportAdminController::class, 'bugUpdate'],
        '/admin/feedback/reply' => [SupportAdminController::class, 'feedbackReply'],
        '/admin/feedback/flag' => [SupportAdminController::class, 'feedbackFlag'],
        '/admin/surveys/save' => [SupportAdminController::class, 'surveySave'],
        '/admin/surveys/status' => [SupportAdminController::class, 'surveyStatus'],
        '/support/bug'       => [SupportController::class, 'bugCreate'],
        '/support/bug/comment' => [SupportController::class, 'bugComment'],
        '/support/bug/fix'   => [SupportController::class, 'bugAnswerFix'],
        '/support/images'    => [SupportController::class, 'imageUpload'],
        '/support/feedback'  => [SupportController::class, 'feedbackCreate'],
        '/support/message/reply' => [SupportController::class, 'threadReply'],
        '/support/survey'    => [SupportController::class, 'surveySubmit'],
        '/admin/notifications/preview' => [NotificationController::class, 'preview'],
        '/admin/bot/disconnect' => [BotController::class, 'disconnect'],
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
        '/admin/games/category' => [AdminController::class, 'gameCategory'],
        '/admin/games/categories' => [AdminController::class, 'findCategories'],
        '/admin/publishers'  => [AdminController::class, 'storePublisher'],
        '/admin/developers'  => [AdminController::class, 'storeDeveloper'],
        '/admin/key-sites'   => [AdminController::class, 'storeKeySite'],
        '/admin/api'         => [AdminController::class, 'saveApiSettings'],
        '/admin/settings'    => [AdminController::class, 'saveSettings'],
        '/admin/settings/domains' => [AdminController::class, 'saveDomains'],
        '/admin/testimonials' => [AdminController::class, 'saveTestimonials'],
        '/admin/testimonials/toggle' => [AdminController::class, 'toggleTestimonial'],
        '/admin/testimonials/section' => [AdminController::class, 'toggleTestimonialsSection'],
        '/admin/users'       => [UserAdminController::class, 'store'],
        '/admin/users/update' => [UserAdminController::class, 'update'],
        '/admin/users/password' => [UserAdminController::class, 'resetPassword'],
        '/admin/users/delete' => [UserAdminController::class, 'delete'],
        '/admin/api/test'    => [AdminController::class, 'testProvider'],
        '/admin/lang'        => [LangController::class, 'save'],
        '/admin/lang/labels' => [LangController::class, 'saveCodeLabels'],
        '/admin/import/game' => [ImportController::class, 'import'],
    ],
];

$handler = $routes[$method][$path] ?? null;

if ($handler === null) {
    error_page(404);
}

try {
    $handler();
} catch (Throwable $e) {
    if ($debug) {
        throw $e;
    }

    error_page(500, ErrorLog::exception($e));
}
