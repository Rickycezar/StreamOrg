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
<html lang="<?= e(Lang::locale()) ?>" <?= Themes::htmlAttributes($user) ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ? $pageTitle . ' · ' . __('ui.app_name') : __('ui.app_name')) ?></title>
    <link rel="icon" href="<?= e(url('/assets/brand/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= e(url('/favicon.ico')) ?>" sizes="16x16 32x32 48x48">
    <link rel="apple-touch-icon" href="<?= e(url('/assets/brand/icon-180.png')) ?>">
    <?php $themeAttrs = Themes::forUser($user); ?>
    <meta name="streamorg-theme" content="<?= e($themeAttrs['light'] . ' ' . $themeAttrs['dark'] . ' ' . $themeAttrs['mode']) ?>">
    <script src="<?= e($asset('/assets/theme.js')) ?>"></script>
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

    <nav class="mainnav" id="mainnav" aria-label="<?= e(__('ui.action.menu')) ?>">
        <div class="drawer-head">
            <span class="brand">
                <?php require dirname(__DIR__) . '/views/partials/logo.php'; ?>
                <span class="brand-word"><?= e($brandSplit[0]) ?><b><?= e($brandSplit[1]) ?></b></span>
            </span>
            <button type="button" class="drawer-close" data-close-nav aria-label="<?= e(__('ui.action.close')) ?>">&times;</button>
        </div>
        <?php
        $nav = [
            ['path' => '/dashboard', 'label' => __('ui.nav.dashboard')],
            [
                'label'    => __('ui.nav.sponsorships'),
                'children' => [
                    '/keys'         => __('ui.nav.vault'),
                    '/giveaways'    => __('ui.nav.giveaways'),
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
                    '/admin/bot'        => __('ui.nav.chat_bot'),
                    '/admin/notifications' => __('ui.nav.notifications'),
                    '/admin/winners'    => __('ui.nav.winners'),
                    '/admin/errors'     => __('ui.nav.errors'),
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

    <?php $unreadNotes = Notifications::unreadCount((int) $user['id']); ?>
    <div class="notify" id="notify">
        <button type="button" class="notify-button" id="notify-button" aria-expanded="false" aria-controls="notify-panel"
                aria-haspopup="true" aria-label="<?= e(__('ui.nav.notifications')) ?>" title="<?= e(__('ui.nav.notifications')) ?>"
                data-panel="<?= e(url('/notifications/panel')) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
            <span class="notify-count" id="notify-count" <?= $unreadNotes > 0 ? '' : 'hidden' ?>><?= $unreadNotes > 99 ? '99+' : $unreadNotes ?></span>
        </button>
        <div class="notify-panel" id="notify-panel" hidden></div>
    </div>

    <div class="usermenu" id="usermenu">
        <button type="button" class="usermenu-button" id="usermenu-button"
                aria-expanded="false" aria-controls="usermenu-panel" aria-haspopup="true"
                aria-label="<?= e(__('ui.label.user_menu')) ?>">
            <?php $avatarUser = $user; $avatarSize = 'sm'; require dirname(__DIR__) . '/views/partials/avatar.php'; ?>
            <span class="keys-badge" id="keys-badge" title="<?= e(__('ui.action.keys_hidden')) ?>"
                  data-on="<?= e(__('ui.action.keys_visible')) ?>" data-off="<?= e(__('ui.action.keys_hidden')) ?>">
                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6zM4 4l16 16"/></svg>
                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6zM12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>
            </span>
            <span class="usermenu-name"><?= e($user['display_name'] ?: $user['username']) ?></span>
        </button>

        <div class="usermenu-panel" id="usermenu-panel" hidden>
            <div class="usermenu-head">
                <?php $avatarSize = 'md'; require dirname(__DIR__) . '/views/partials/avatar.php'; ?>
                <span>
                    <strong><?= e($user['display_name'] ?: $user['username']) ?></strong>
                    <?php if (!empty($user['channel_handle'])): ?>
                        <small><?= e(code_label('streaming_platform', $user['channel_platform_code'] ?? '')) ?>/<?= e($user['channel_handle']) ?></small>
                    <?php else: ?>
                        <small>@<?= e($user['username']) ?></small>
                    <?php endif; ?>
                </span>
            </div>

            <button type="button" id="reveal-toggle" class="usermenu-row reveal-toggle" aria-pressed="false"
                    data-on="<?= e(__('ui.action.keys_visible')) ?>"
                    data-off="<?= e(__('ui.action.keys_hidden')) ?>"
                    title="<?= e(__('ui.label.reveal_toggle_hint')) ?>">
                <span class="usermenu-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 10a4 4 0 1 0-3.9 4H11l1.5 1.5L14 14l1.5 1.5L17 14l2 2M7.5 10.5h.01"/></svg>
                </span>
                <span class="txt"><?= e(__('ui.action.keys_hidden')) ?></span>
                <span class="switch-track"><span class="switch-thumb"></span></span>
            </button>

            <div class="usermenu-row usermenu-mode">
                <span class="usermenu-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9z"/></svg>
                </span>
                <span class="segmented small" role="radiogroup" aria-label="<?= e(__('ui.label.theme_mode')) ?>">
                    <?php foreach (Themes::MODES as $mode): ?>
                        <button type="button" role="radio" data-set-mode="<?= e($mode) ?>"
                                aria-checked="<?= $themeAttrs['mode'] === $mode ? 'true' : 'false' ?>"><?= e(__('ui.label.mode_' . $mode)) ?></button>
                    <?php endforeach; ?>
                </span>
            </div>

            <a class="usermenu-row" href="<?= e(url('/profile')) ?>">
                <span class="usermenu-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                </span>
                <?= e(__('ui.nav.profile')) ?>
            </a>
            <a class="usermenu-row" href="<?= e(url('/profile/appearance')) ?>">
                <span class="usermenu-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21a9 9 0 1 1 9-9c0 2-1.5 3-3 3h-2a2 2 0 0 0-1 3.7A1.5 1.5 0 0 1 12 21zM7.5 11h.01M10 7h.01M15 7.5h.01"/></svg>
                </span>
                <?= e(__('ui.label.profile_appearance')) ?>
            </a>

            <?php if (TwitchUser::connection((int) $user['id']) !== null || Viewers::forUser((int) $user['id']) !== null): ?>
                <a class="usermenu-row" href="<?= e(url('/prizes')) ?>">
                    <span class="usermenu-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11h16v10H4zM2 7h20v4H2zM12 7v14M12 7c-2-4-6-4-6-1.5S9 7 12 7zM12 7c2-4 6-4 6-1.5S15 7 12 7z"/></svg>
                    </span>
                    <?= e(__('ui.viewer.prizes_title')) ?>
                </a>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/logout')) ?>" data-turbo="false" class="usermenu-logout">
                <?= Csrf::field() ?>
                <button type="submit" class="usermenu-row">
                    <span class="usermenu-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/></svg>
                    </span>
                    <?= e(__('ui.action.logout')) ?>
                </button>
            </form>
        </div>
    </div>
</header>
<div class="nav-backdrop" id="nav-backdrop" hidden></div>
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
        'in_catalog' => __('ui.label.in_catalog'),
    ],
];
?>
<script type="application/json" data-globals><?= json_encode($globals, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</body>
</html>
