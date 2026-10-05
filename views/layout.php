<?php
/** @var string $content */
/** @var ?string $pageTitle */
$user = Auth::user();

$here = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$isHere = static function (string $path) use ($here): bool {
    return $path === '/' ? $here === '/' : str_starts_with($here, $path);
};

$brand = __('ui.app_name');
$brandSplit = str_contains($brand, 'Org')
    ? [substr($brand, 0, strpos($brand, 'Org')), 'Org']
    : [$brand, ''];

$asset = static fn (string $path): string => url($path) . '?v=' . (@filemtime(dirname(__DIR__) . '/public' . $path) ?: 0);
?>
<!DOCTYPE html>
<html lang="<?= e(Lang::locale()) ?>" data-theme="<?= e($user['theme'] ?? 'light') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ? $pageTitle . ' · ' . __('ui.app_name') : __('ui.app_name')) ?></title>
    <link rel="icon" href="<?= e(url('/assets/brand/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= e(url('/favicon.ico')) ?>" sizes="16x16 32x32 48x48">
    <link rel="apple-touch-icon" href="<?= e(url('/assets/brand/icon-180.png')) ?>">
    <meta name="streamorg-theme" content="<?= e($user['theme'] ?? 'light') ?>">
    <meta name="streamorg-lang" content="<?= e(Lang::locale()) ?>">
    <link rel="stylesheet" href="<?= e($asset('/assets/vendor/tom-select.css')) ?>" data-turbo-track="reload">
    <link rel="stylesheet" href="<?= e($asset('/assets/app.css')) ?>" data-turbo-track="reload">
    <?php if ($user === null): ?>
    <link rel="stylesheet" href="<?= e($asset('/assets/transitions.css')) ?>">
    <?php endif; ?>
    <script src="<?= e($asset('/assets/vendor/turbo.js')) ?>" defer data-turbo-track="reload"></script>
    <script src="<?= e($asset('/assets/vendor/tom-select.js')) ?>" defer data-turbo-track="reload"></script>
    <script src="<?= e($asset('/assets/app.js')) ?>" defer data-turbo-track="reload"></script>
</head>
<body class="<?= $user !== null ? 'has-topbar' : '' ?>">
<?php if ($user !== null): ?>
<header class="topbar">
    <a class="brand" href="<?= e(url('/dashboard')) ?>">
        <?php require dirname(__DIR__) . '/views/partials/logo.php'; ?>
        <span class="brand-word"><?= e($brandSplit[0]) ?><b><?= e($brandSplit[1]) ?></b></span>
    </a>

    <button type="button" class="navtoggle" id="nav-toggle"
            aria-expanded="false" aria-controls="mainnav"
            aria-label="<?= e(__('ui.action.menu')) ?>">
        <span></span><span></span><span></span>
    </button>

    <nav class="mainnav" id="mainnav">
        <?php
        $nav = [
            ['path' => '/dashboard', 'label' => __('ui.nav.dashboard')],
            [
                'label'    => __('ui.nav.sponsorships'),
                'children' => [
                    '/keys'         => __('ui.nav.vault'),
                    '/negotiations' => __('ui.nav.negotiations'),
                ],
            ],
            [
                'label'    => __('ui.nav.content'),
                'children' => [
                    '/content'   => __('ui.nav.content'),
                    '/collabs'   => __('ui.nav.collabs'),
                    '/streamers' => __('ui.nav.streamers'),
                    '/embargoes' => __('ui.nav.embargoes'),
                ],
            ],
            ['path' => '/catalog', 'label' => __('ui.nav.catalog')],
        ];

        if (Auth::isAdmin()) {
            $nav[] = [
                'label'    => __('ui.nav.admin'),
                'children' => [
                    '/admin'            => __('ui.label.overview'),
                    '/admin/users'      => __('ui.nav.users'),
                    '/admin/games'      => __('ui.nav.games'),
                    '/admin/publishers' => __('ui.nav.publishers'),
                    '/admin/developers' => __('ui.nav.developers'),
                    '/admin/key-sites'  => __('ui.nav.key_sites'),
                    '/admin/import'     => __('ui.nav.import'),
                    '/admin/api'        => __('ui.nav.api_settings'),
                    '/admin/settings'   => __('ui.nav.settings'),
                    '/admin/testimonials' => __('ui.nav.testimonials'),
                    '/admin/lang'       => __('ui.nav.languages'),
                ],
            ];
        }

        foreach ($nav as $i => $item):
            if (!isset($item['children'])):
                $on = $isHere($item['path']);
                ?>
                <a href="<?= e(url($item['path'])) ?>" class="<?= $on ? 'active' : '' ?>"
                   <?= $on ? 'aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
            <?php
                continue;
            endif;

            $on = false;

            foreach (array_keys($item['children']) as $childPath) {
                $on = $on || $isHere($childPath);
            }
            ?>
            <div class="navgroup <?= $on ? 'active' : '' ?>">
                <button type="button" class="navtop" aria-expanded="false"
                        aria-controls="navmenu-<?= $i ?>"><?= e($item['label']) ?></button>
                <div class="navmenu" id="navmenu-<?= $i ?>">
                    <?php foreach ($item['children'] as $childPath => $childLabel):
                        $childOn = $isHere($childPath) && ($childPath !== '/admin' || $here === '/admin'); ?>
                        <a href="<?= e(url($childPath)) ?>" class="<?= $childOn ? 'active' : '' ?>"
                           <?= $childOn ? 'aria-current="page"' : '' ?>><?= e($childLabel) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="userbox">
        <button type="button" id="reveal-toggle" class="reveal-toggle" aria-pressed="false"
                data-on="<?= e(__('ui.action.keys_visible')) ?>"
                data-off="<?= e(__('ui.action.keys_hidden')) ?>"
                title="<?= e(__('ui.label.reveal_toggle_hint')) ?>">
            <span class="dot"></span><span class="txt"><?= e(__('ui.action.keys_hidden')) ?></span>
        </button>

        <a class="who" href="<?= e(url('/profile')) ?>">
            <?= e($user['display_name'] ?: $user['username']) ?>
            <?php if (!empty($user['channel_handle'])): ?>
                <small><?= e(code_label('streaming_platform', $user['channel_platform_code'] ?? '')) ?>/<?= e($user['channel_handle']) ?></small>
            <?php endif; ?>
        </a>
        <form method="post" action="<?= e(url('/logout')) ?>" data-turbo="false">
            <?= Csrf::field() ?>
            <button type="submit" class="linkish"><?= e(__('ui.action.logout')) ?></button>
        </form>
    </div>
</header>
<?php endif; ?>

<main class="<?= $user === null ? 'centered' : '' ?>">
    <?php foreach (take_flashes() as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<div id="modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-backdrop" data-close-modal></div>
    <div class="modal-panel">
        <div class="modal-head">
            <h2 id="modal-title"></h2>
            <button type="button" class="modal-close" data-close-modal aria-label="<?= e(__('ui.action.close')) ?>">&times;</button>
        </div>
        <div id="modal-body" class="modal-body"></div>
    </div>
</div>

<?php
$globals = [
    'CSRF_TOKEN'        => Csrf::token(),
    'STREAMORG_SESSION' => Auth::clientMarker(),
    'BASE_URL'          => url('/'),
    'STREAMORG_PREFS'   => PreferenceController::all() ?: new stdClass(),
    'STREAMORG_TWITCH_L' => [
        'heading'    => __('ui.action.twitch_send'),
        'channel'    => __('ui.label.twitch_channel'),
        'title'      => __('ui.field.title'),
        'now'        => __('ui.label.twitch_now'),
        'category'   => __('ui.label.twitch_category'),
        'keep'       => __('ui.label.twitch_category_keep'),
        'choose'     => __('ui.label.twitch_category_choose'),
        'none_found' => __('ui.message.twitch_no_category'),
        'tags'       => __('ui.label.twitch_tags'),
        'added'      => __('ui.label.twitch_tag_added'),
        'removed'    => __('ui.label.twitch_tag_removed'),
        'no_tags'    => __('ui.label.twitch_no_tags'),
        'too_long'   => __('ui.message.twitch_title_too_long'),
        'send'       => __('ui.action.twitch_send'),
        'cancel'     => __('ui.action.cancel'),
    ],
    'STREAMORG_L'       => [
        'status' => __('ui.field.status'),   'platform'  => __('ui.field.platform'),
        'scheduled' => __('ui.field.scheduled'), 'deadline' => __('ui.field.deadline'),
        'started' => __('ui.field.started'), 'ended'     => __('ui.field.ended'),
        'peak' => __('ui.field.peak_viewers'), 'avg'     => __('ui.field.avg_viewers'),
        'collab' => __('ui.label.collab'),   'vod'       => __('ui.field.vod_url'),
        'notes' => __('ui.field.notes'),     'created'   => __('ui.field.created_at'),
        'updated' => __('ui.field.updated_at'), 'games'  => __('ui.nav.games'),
        'release' => __('ui.field.release_date'), 'coverage' => __('ui.nav.coverage'),
        'embargo' => __('ui.field.embargo'), 'note'      => __('ui.field.notes'),
        'key' => __('ui.field.key'),         'source'    => __('ui.field.source'),
        'redeem_by' => __('ui.field.redeem_by'), 'no_key' => __('ui.label.no_key'),
        'undated' => __('ui.label.undated'), 'collaborators' => __('ui.nav.streamers'),
        'cancel' => __('ui.action.cancel'), 'confirm_anyway' => __('ui.action.confirm_anyway'),
        'embargo_giveaway_title' => __('ui.message.embargo_giveaway_title'),
        'needs_date' => __('ui.label.needs_date'),
        'confirm_delete_title' => __('ui.action.delete'),
        'confirm_delete' => __('ui.message.confirm_delete'),
        'confirm_delete_named' => __('ui.message.confirm_delete_named'),
        'columns' => __('ui.action.columns'), 'peek' => __('ui.action.peek'),
        'key_type' => __('ui.field.key_type'), 'content_type' => __('ui.field.content_type'),
        'region' => __('ui.field.region'), 'received' => __('ui.field.received'),
        'key_code' => __('ui.field.key_code'),
        'picker_search' => __('ui.label.picker_search'), 'picker_none' => __('ui.label.picker_none'),
        'picker_games' => __('ui.label.picker_games'), 'picker_clear' => __('ui.action.clear'),
        'activated' => __('ui.field.activated'), 'provenance' => __('ui.field.provenance'),
        'publisher' => __('ui.nav.publishers'), 'developer' => __('ui.nav.developers'),
        'store_url' => __('ui.field.store_url'), 'title' => __('ui.field.title'),
        'negotiation' => __('ui.nav.negotiations'), 'used_in' => __('ui.label.used_in'),
        'giveaway' => __('ui.nav.giveaways'), 'winner' => __('ui.field.winner'),
        'won_at' => __('ui.field.won_at'), 'delivered' => __('ui.field.delivered'),
        'under_embargo_until' => __('ui.label.under_embargo_until'),
    ],
];
?>
<script type="application/json" data-globals><?= json_encode($globals, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</body>
</html>
