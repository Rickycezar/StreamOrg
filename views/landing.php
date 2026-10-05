<?php
/**
 * Public landing page. A full document of its own (not wrapped in the app
 * layout): visitors have no menu, and the page must work without app.js.
 * Always dark, whatever theme a signed-in user picked for the app.
 *
 * @var bool    $signedIn
 * @var string  $email   preview-access contact address
 * @var ?string $mailto
 * @var list<array{title:string, url:string}> $art  catalogue box art for the illustrations
 * @var list<array{name:string, quote:string, detail:?string, avatar:?string, url:?string, rating:int}> $testimonials
 */

$asset = static fn (string $path): string => url($path) . '?v=' . (@filemtime(dirname(__DIR__) . '/public' . $path) ?: 0);
$enter = $signedIn ? url('/dashboard') : url('/login');

$icon = static function (string $path, string $class = '', float $stroke = 1.8): string {
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $stroke
        . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . e($path) . '"/></svg>';
};

$I = [
    'twitch'   => 'M4 3h16v11l-4 4h-4l-3 3v-3H4zM10 8v4M15 8v4',
    'gamepad'  => 'M6 12h4M8 10v4M15 13h.01M18 11h.01M17.3 5H6.7a4 4 0 0 0-3.98 3.59L2 15.6a2.5 2.5 0 0 0 4.3 2L8 16h8l1.7 1.6a2.5 2.5 0 0 0 4.3-2l-.72-7A4 4 0 0 0 17.3 5z',
    'calendar' => 'M4 6h16v14H4zM4 10h16M9 3v4M15 3v4M8 14h3v3H8z',
    'lock'     => 'M7 11V8a5 5 0 0 1 10 0v3M5 11h14v10H5zM12 15v2',
    'alert'    => 'M12 3l9 16H3zM12 10v4M12 17h.01',
    'users'    => 'M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM17 11a3 3 0 1 0 0-6M3 20c0-3 3-5 6-5s6 2 6 5M15 15c3 0 6 2 6 5',
    'mail'     => 'M3 6h18v12H3zM3 7l9 6 9-6',
    'arrow'    => 'M5 12h14M13 6l6 6-6 6',
    'send'     => 'M22 2L11 13M22 2l-7 20-4-9-9-4z',
    'check'    => 'M9 12l2 2 4-4M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
    'clock'    => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
    'left'     => 'M15 18l-6-6 6-6',
    'right'    => 'M9 18l6-6-6-6',
    'user'     => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c0-4 4-6 8-6s8 2 8 6',
    'plus'     => 'M12 5v14M5 12h14',
    'cursor'   => 'M5 3l14 8-6 2-2 6z',
    'gift'     => 'M4 11h16v10H4zM2 7h20v4H2zM12 7v14M12 7c-2-4-6-4-6-1.5S9 7 12 7zM12 7c2-4 6-4 6-1.5S15 7 12 7z',
];

$features = [
    ['vault',   'lock',     'blue'],
    ['planner', 'calendar', 'violet'],
    ['catalog', 'gamepad',  'green'],
    ['twitch',  'twitch',   'twitch'],
    ['embargo', 'alert',    'orange'],
    ['collabs', 'gift',     'pink'],
];

$today   = new DateTimeImmutable('today');
$weekday = [7, 1, 2, 3, 4, 5, 6];
$events  = [3 => ['19:00', 'ui.landing.mock_launch', 'blue'], 10 => ['16:00', 'ui.landing.mock_review', 'green'], 16 => ['20:00', 'ui.landing.mock_live', 'violet']];
$gameTag = [
    ['check', 'ui.landing.mock_key_received', 'blue', null],
    ['check', 'ui.landing.mock_in_review', 'green', null],
    ['clock', 'ui.landing.mock_embargo', 'amber', $today->modify('+14 days')->format('d/m')],
];

[$noteBefore, $noteAfter] = array_pad(explode('%s', __('ui.landing.preview_note'), 2), 2, '');

$initials = static function (string $name): string {
    preg_match_all('/\p{Lu}|\b\p{L}/u', $name, $m);
    return mb_strtoupper(implode('', array_slice($m[0], 0, 2))) ?: '?';
};
?>
<!DOCTYPE html>
<html lang="<?= e(Lang::locale()) ?>" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('ui.app_name')) ?> · <?= e(__('ui.landing.tagline')) ?></title>
    <meta name="description" content="<?= e(__('ui.landing.lead')) ?>">
    <meta name="theme-color" content="#060a18">
    <?php foreach ((array) Config::get('app.domain_locales', []) as $domain => $locale): ?>
        <link rel="alternate" hreflang="<?= e((string) $locale) ?>" href="https://<?= e((string) $domain) ?>/">
    <?php endforeach; ?>
    <meta property="og:title" content="<?= e(__('ui.app_name')) ?>">
    <meta property="og:description" content="<?= e(__('ui.landing.lead')) ?>">
    <meta property="og:image" content="<?= e(url('/assets/brand/logo-576.png')) ?>">
    <link rel="icon" href="<?= e(url('/assets/brand/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= e(url('/favicon.ico')) ?>" sizes="16x16 32x32 48x48">
    <link rel="apple-touch-icon" href="<?= e(url('/assets/brand/icon-180.png')) ?>">
    <link rel="preload" href="<?= e(url('/assets/vendor/fonts/plus-jakarta-sans-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e($asset('/assets/app.css')) ?>">
    <link rel="stylesheet" href="<?= e($asset('/assets/landing.css')) ?>">
    <link rel="stylesheet" href="<?= e($asset('/assets/transitions.css')) ?>">
    <script src="<?= e($asset('/assets/landing.js')) ?>" defer></script>
</head>
<body class="landing">

<header class="landing-bar">
    <a class="brand" href="<?= e(url('/')) ?>">
        <?php require __DIR__ . '/partials/logo.php'; ?>
        <span class="brand-word">Stream<b>Org</b></span>
    </a>
    <div class="landing-bar-actions">
        <?php if (!$signedIn) { require __DIR__ . '/partials/language_switch.php'; } ?>
        <a class="lp-btn ghost small" href="<?= e($enter) ?>" data-turbo="false">
            <?= e(__($signedIn ? 'ui.landing.open_app' : 'ui.landing.sign_in')) ?>
        </a>
    </div>
</header>

<main class="landing-main">
    <section class="lp-hero">
        <div class="lp-copy">
            <span class="lp-badge"><?= e(__('ui.landing.badge')) ?></span>
            <h1><?= e(__('ui.landing.headline')) ?> <span class="lp-gradient"><?= e(__('ui.landing.headline_accent')) ?></span></h1>
            <p class="lp-lead"><?= e(__('ui.landing.lead')) ?></p>

            <div class="lp-actions">
                <a class="lp-btn primary" href="<?= e($enter) ?>" data-turbo="false">
                    <?= $icon($I['arrow'], 'lp-btn-icon', 2.2) ?>
                    <?= e(__($signedIn ? 'ui.landing.open_app' : 'ui.landing.use_system')) ?>
                </a>
                <?php if ($mailto !== null): ?>
                    <a class="lp-btn ghost" href="<?= e($mailto) ?>"><?= e(__('ui.landing.request_preview')) ?></a>
                <?php endif; ?>
            </div>

            <?php if ($mailto !== null): ?>
                <p class="lp-note">
                    <?= $icon($I['mail'], 'lp-note-icon') ?>
                    <span><?= e($noteBefore) ?><a href="<?= e($mailto) ?>"><?= e($email) ?></a><?= e($noteAfter) ?></span>
                </p>
            <?php endif; ?>
            <?php if (!$signedIn): ?>
                <p class="lp-note">
                    <?= $icon($I['gift'], 'lp-note-icon') ?>
                    <span><?= e(__('ui.landing.won_a_key')) ?> <a href="<?= e(url('/login?as=viewer')) ?>" data-turbo="false"><?= e(__('ui.landing.claim_it')) ?></a></span>
                </p>
            <?php endif; ?>
        </div>

        <div class="lp-stage" aria-hidden="true">
            <div class="lp-mock">
                <div class="lp-mock-bar"><span></span><span></span><span></span></div>
                <div class="lp-mock-body">
                    <div class="lp-cal">
                        <div class="lp-cal-head">
                            <b><?= e(__('ui.landing.mock_calendar')) ?></b>
                            <span><?= e(ucfirst(__('ui.month.' . $today->format('n')))) ?> <?= e($today->format('Y')) ?></span>
                        </div>
                        <div class="lp-cal-grid">
                            <?php foreach ($weekday as $d): ?>
                                <span class="lp-cal-dow"><?= e(__('ui.weekday_short.' . $d)) ?></span>
                            <?php endforeach; ?>
                            <?php for ($day = 1; $day <= 21; $day++): ?>
                                <span class="lp-cal-day<?= $day === 11 ? ' today' : '' ?>">
                                    <i><?= $day ?></i>
                                    <?php if (isset($events[$day])): [$time, $label, $tone] = $events[$day]; ?>
                                        <b class="lp-chip <?= $tone ?>"><?= e($time) ?><small><?= e(__($label)) ?></small></b>
                                    <?php endif; ?>
                                </span>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="lp-games">
                        <b class="lp-games-head"><?= e(__('ui.landing.mock_games')) ?></b>
                        <?php foreach ($gameTag as $i => [$tagIcon, $tagLabel, $tone, $arg]): $game = $art[$i] ?? null; ?>
                            <div class="lp-game">
                                <?php if ($game !== null): ?>
                                    <img src="<?= e($game['url']) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="lp-art-empty"></span>
                                <?php endif; ?>
                                <span class="lp-game-text">
                                    <span class="lp-game-title"><?= e($game['title'] ?? '') ?></span>
                                    <span class="lp-tag <?= $tone ?>"><?= $icon($I[$tagIcon], '', 2.2) ?><?= e($arg === null ? __($tagLabel) : sprintf(__($tagLabel), $arg)) ?></span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <span class="lp-float twitch"><?= $icon($I['twitch']) ?></span>
            <span class="lp-float pad"><?= $icon($I['gamepad']) ?></span>
            <span class="lp-float cal"><?= $icon($I['calendar']) ?></span>
        </div>
    </section>

    <section class="lp-banner">
        <div class="lp-banner-copy">
            <span class="lp-eyebrow"><?= e(__('ui.landing.banner_eyebrow')) ?></span>
            <h2><?= e(__('ui.landing.banner_title')) ?></h2>
            <p><?= e(__('ui.landing.banner_text')) ?></p>
        </div>
        <div class="lp-folders" aria-hidden="true">
            <span class="lp-folder back"></span>
            <span class="lp-paper"></span>
            <span class="lp-folder front"><?= $icon($I['gamepad']) ?></span>
            <span class="lp-folder-badge"><?= $icon($I['calendar']) ?></span>
        </div>
    </section>

    <section class="lp-features">
        <?php foreach ($features as [$key, $iconName, $tone]): ?>
            <article class="lp-feature tone-<?= $tone ?>">
                <header>
                    <span class="lp-icon"><?= $icon($I[$iconName]) ?></span>
                    <h3><?= e(__('ui.landing.feature_' . $key . '_title')) ?></h3>
                </header>
                <p><?= e(__('ui.landing.feature_' . $key . '_text')) ?></p>
                <div class="lp-mini mini-<?= $key ?>" aria-hidden="true">
                    <?php if ($key === 'vault'): ?>
                        <span class="lp-mini-tile"><?= $icon($I['lock']) ?></span>
                        <span class="lp-mini-code">•••• •••• ••••</span>
                    <?php elseif ($key === 'planner'): ?>
                        <div class="lp-mini-week">
                            <?php foreach ([1, 2, 3, 4, 5, 6] as $d): ?><span><?= e(__('ui.weekday_short.' . $d)) ?></span><?php endforeach; ?>
                            <?php for ($c = 0; $c < 12; $c++): ?><i class="<?= [1 => 'blue', 2 => 'green', 9 => 'violet wide'][$c] ?? '' ?>"></i><?php endfor; ?>
                        </div>
                        <?= $icon($I['cursor'], 'lp-mini-cursor', 1.6) ?>
                    <?php elseif ($key === 'catalog'): ?>
                        <div class="lp-fan">
                            <?php for ($c = 0; $c < 5; $c++): $game = $art[($c + 2) % max(count($art), 1)] ?? null; ?>
                                <?php if ($game !== null): ?>
                                    <img src="<?= e($game['url']) ?>" alt="" loading="lazy" class="f<?= $c ?>">
                                <?php else: ?>
                                    <span class="lp-art-empty f<?= $c ?>"></span>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    <?php elseif ($key === 'twitch'): ?>
                        <span class="lp-mini-tile"><?= $icon($I['twitch']) ?></span>
                        <span class="lp-mini-form">
                            <span><?= e(__('ui.field.title')) ?><i></i></span>
                            <span><?= e(__('ui.label.twitch_category')) ?><i></i></span>
                            <span><?= e(__('ui.label.twitch_tags')) ?><i></i></span>
                            <b><?= $icon($I['send'], '', 2) ?><?= e(__('ui.action.twitch_send')) ?></b>
                        </span>
                    <?php elseif ($key === 'embargo'): ?>
                        <span class="lp-mini-alert">
                            <?= $icon($I['alert'], '', 2) ?>
                            <span><b><?= e(__('ui.landing.mini_embargo')) ?></b><i></i><i class="short"></i></span>
                        </span>
                    <?php else: ?>
                        <span class="lp-mini-tile"><?= $icon($I['gift']) ?></span>
                        <span class="lp-mini-claim">
                            <b><?= e(__('ui.landing.mini_claim')) ?></b>
                            <span><i></i><?= $icon($I['twitch'], '', 2) ?></span>
                        </span>
                        <span class="lp-avatar add"><?= $icon($I['users'], '', 2) ?></span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <?php if ($testimonials !== []): ?>
    <section class="lp-testimonials" data-carousel>
        <span class="lp-eyebrow"><?= e(__('ui.landing.testimonials_eyebrow')) ?></span>
        <h2><?= e(__('ui.landing.testimonials_title')) ?></h2>
        <p class="lp-sub"><?= e(__('ui.landing.testimonials_text')) ?></p>

        <div class="lp-carousel">
            <?php if (count($testimonials) > 1): ?>
                <button type="button" class="lp-arrow prev" data-carousel-prev aria-label="<?= e(__('ui.landing.testimonials_prev')) ?>"><?= $icon($I['left'], '', 2.2) ?></button>
            <?php endif; ?>
            <div class="lp-track" data-carousel-track>
                <?php foreach ($testimonials as $t): ?>
                    <figure class="lp-quote">
                        <?php if ($t['avatar']): ?>
                            <img class="lp-quote-avatar" src="<?= e($t['avatar']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                        <?php else: ?>
                            <span class="lp-quote-avatar initials"><?= e($initials($t['name'])) ?></span>
                        <?php endif; ?>
                        <div>
                            <span class="lp-stars" aria-label="<?= (int) $t['rating'] ?>/5"><?= str_repeat('★', $t['rating']) ?><span><?= str_repeat('★', 5 - $t['rating']) ?></span></span>
                            <blockquote>“<?= e($t['quote']) ?>”</blockquote>
                            <figcaption>
                                <?php if ($t['url']): ?>
                                    <a href="<?= e($t['url']) ?>" target="_blank" rel="noopener nofollow"><?= e($t['name']) ?></a>
                                <?php else: ?>
                                    <b><?= e($t['name']) ?></b>
                                <?php endif; ?>
                                <?php if ($t['detail']): ?>
                                    <small><?= $icon($I['twitch']) ?><?= e($t['detail']) ?></small>
                                <?php endif; ?>
                            </figcaption>
                        </div>
                    </figure>
                <?php endforeach; ?>
            </div>
            <?php if (count($testimonials) > 1): ?>
                <button type="button" class="lp-arrow next" data-carousel-next aria-label="<?= e(__('ui.landing.testimonials_next')) ?>"><?= $icon($I['right'], '', 2.2) ?></button>
            <?php endif; ?>
        </div>

        <?php if (count($testimonials) > 1): ?>
            <div class="lp-dots" data-carousel-dots>
                <?php foreach ($testimonials as $i => $t): ?>
                    <button type="button" aria-label="<?= e(sprintf(__('ui.landing.testimonials_goto'), $i + 1)) ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($mailto !== null): ?>
    <section class="lp-cta">
        <div class="lp-envelope" aria-hidden="true">
            <span class="lp-letter"><i></i><i></i><i class="short"></i></span>
            <span class="lp-env-back"></span>
            <span class="lp-env-front"></span>
        </div>
        <div class="lp-cta-copy">
            <span class="lp-eyebrow"><?= e(__('ui.landing.cta_title')) ?></span>
            <h2><?= e(__('ui.landing.cta_heading')) ?></h2>
            <p><?= e(__('ui.landing.cta_sub')) ?></p>
            <a class="lp-btn primary" href="<?= e($mailto) ?>"><?= $icon($I['mail'], 'lp-btn-icon') ?><?= e(__('ui.landing.request_preview')) ?></a>
        </div>
    </section>
    <?php endif; ?>
</main>

<footer class="lp-footer">
    <a class="brand" href="<?= e(url('/')) ?>">
        <?php require __DIR__ . '/partials/logo.php'; ?>
        <span class="brand-word">Stream<b>Org</b></span>
    </a>
    <span>© <?= date('Y') ?> StreamOrg · <a href="<?= e(url('/privacy')) ?>" data-turbo="false"><?= e(__('ui.privacy.title')) ?></a></span>
    <?php if ($email !== ''): ?>
        <a class="lp-footer-mail" href="mailto:<?= e($email) ?>"><?= $icon($I['mail']) ?><?= e($email) ?></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
</footer>

</body>
</html>
