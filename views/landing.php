<?php
/**
 * Public landing page. A full document of its own (not wrapped in the app
 * layout): visitors have no menu, and the page must work without app.js.
 *
 * @var bool    $signedIn
 * @var ?string $theme   the signed-in user's theme; null for visitors
 * @var string  $email   preview-access contact address
 * @var ?string $mailto
 */

$asset = static fn (string $path): string => url($path) . '?v=' . (@filemtime(dirname(__DIR__) . '/public' . $path) ?: 0);
$enter = $signedIn ? url('/dashboard') : url('/login');

$features = [
    ['vault',    'M7 11V8a5 5 0 0 1 10 0v3M5 11h14v10H5zM12 15v2'],
    ['planner',  'M4 6h16v14H4zM4 10h16M9 3v4M15 3v4M8 14h3v3H8z'],
    ['catalog',  'M4 5h7v14H4zM13 5h7v6h-7zM13 13h7v6h-7z'],
    ['twitch',   'M5 4h15v10l-4 4h-4l-3 3v-3H5zM11 8v4M15 8v4'],
    ['embargo',  'M12 3l9 16H3zM12 10v4M12 17h.01'],
    ['collabs',  'M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM17 11a3 3 0 1 0 0-6M3 20c0-3 3-5 6-5s6 2 6 5M15 15c3 0 6 2 6 5'],
];
?>
<!DOCTYPE html>
<html lang="<?= e(Lang::locale()) ?>" data-theme="<?= e($theme ?? 'light') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('ui.app_name')) ?> · <?= e(__('ui.landing.tagline')) ?></title>
    <meta name="description" content="<?= e(__('ui.landing.lead')) ?>">
    <?php foreach ((array) Config::get('app.domain_locales', []) as $domain => $locale): ?>
        <link rel="alternate" hreflang="<?= e((string) $locale) ?>" href="https://<?= e((string) $domain) ?>/">
    <?php endforeach; ?>
    <meta property="og:title" content="<?= e(__('ui.app_name')) ?>">
    <meta property="og:description" content="<?= e(__('ui.landing.lead')) ?>">
    <meta property="og:image" content="<?= e(url('/assets/brand/logo-576.png')) ?>">
    <link rel="icon" href="<?= e(url('/assets/brand/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= e(url('/favicon.ico')) ?>" sizes="16x16 32x32 48x48">
    <link rel="apple-touch-icon" href="<?= e(url('/assets/brand/icon-180.png')) ?>">
    <link rel="stylesheet" href="<?= e($asset('/assets/app.css')) ?>">
    <?php if ($theme === null): ?>
    <script src="<?= e($asset('/assets/theme.js')) ?>"></script>
    <?php endif; ?>
</head>
<body class="landing">

<header class="landing-bar">
    <a class="brand" href="<?= e(url('/')) ?>">
        <?php require __DIR__ . '/partials/logo.php'; ?>
        <span class="brand-word">Stream<b>Org</b></span>
    </a>
    <div class="landing-bar-actions">
        <?php if (!$signedIn) { require __DIR__ . '/partials/language_switch.php'; } ?>
        <a class="btn" href="<?= e($enter) ?>" data-turbo="false">
            <?= e(__($signedIn ? 'ui.landing.open_app' : 'ui.landing.sign_in')) ?>
        </a>
    </div>
</header>

<main class="landing-main">
    <section class="landing-hero">
        <div class="landing-copy">
            <span class="landing-badge"><?= e(__('ui.landing.badge')) ?></span>
            <h1><?= e(__('ui.landing.headline')) ?></h1>
            <p class="landing-lead"><?= e(__('ui.landing.lead')) ?></p>

            <div class="landing-actions">
                <a class="btn primary big" href="<?= e($enter) ?>" data-turbo="false">
                    <?= e(__($signedIn ? 'ui.landing.open_app' : 'ui.landing.use_system')) ?>
                </a>
                <?php if ($mailto !== null): ?>
                    <a class="btn big" href="<?= e($mailto) ?>"><?= e(__('ui.landing.request_preview')) ?></a>
                <?php endif; ?>
            </div>
            <?php if ($mailto !== null): ?>
                <p class="landing-note muted small"><?= e(sprintf(__('ui.landing.preview_note'), $email)) ?></p>
            <?php endif; ?>
        </div>

        <div class="landing-shot" aria-hidden="true">
            <div class="shot-bar"><span></span><span></span><span></span></div>
            <div class="shot-body">
                <div class="shot-cal">
                    <?php for ($i = 1; $i <= 21; $i++): ?>
                        <span class="shot-day<?= in_array($i, [3, 9, 16], true) ? ' has' : '' ?><?= $i === 11 ? ' today' : '' ?>">
                            <i><?= $i ?></i>
                            <?php if ($i === 3): ?><b class="chip">19:00</b><?php endif; ?>
                            <?php if ($i === 9): ?><b class="chip alt">16:00</b><?php endif; ?>
                            <?php if ($i === 16): ?><b class="chip">20:00</b><?php endif; ?>
                        </span>
                    <?php endfor; ?>
                </div>
                <div class="shot-side">
                    <span class="shot-item"><b></b><i></i></span>
                    <span class="shot-item"><b></b><i></i></span>
                    <span class="shot-key">•••• •••• ••••</span>
                </div>
            </div>
        </div>
    </section>

    <section class="landing-features">
        <h2><?= e(__('ui.landing.features_title')) ?></h2>
        <div class="feature-grid">
            <?php foreach ($features as [$key, $icon]): ?>
                <article class="feature">
                    <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="<?= e($icon) ?>"/>
                    </svg>
                    <h3><?= e(__('ui.landing.feature_' . $key . '_title')) ?></h3>
                    <p class="muted"><?= e(__('ui.landing.feature_' . $key . '_text')) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($mailto !== null): ?>
    <section class="landing-cta">
        <h2><?= e(__('ui.landing.cta_title')) ?></h2>
        <p class="muted"><?= e(__('ui.landing.cta_text')) ?></p>
        <a class="btn primary big" href="<?= e($mailto) ?>"><?= e(__('ui.landing.request_preview')) ?></a>
    </section>
    <?php endif; ?>
</main>

<footer class="landing-footer muted small">
    <span>© <?= date('Y') ?> StreamOrg</span>
    <?php if ($email !== ''): ?>
        <span>·</span>
        <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>
    <?php endif; ?>
</footer>

</body>
</html>
