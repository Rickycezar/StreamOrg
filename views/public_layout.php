<?php
/**
 * Layout of the pages for the general public — claim links, a viewer's
 * prizes, the privacy policy — in the landing page's look. Always dark,
 * with no app menu; whoever is signed in (a viewer, or a creator) shows
 * on the right.
 *
 * @var string  $content
 * @var ?string $pageTitle
 */
$asset   = static fn (string $path): string => url($path) . '?v=' . (@filemtime(dirname(__DIR__) . '/public' . $path) ?: 0);
$viewer  = Viewers::current();
$creator = Auth::user();
$email   = (string) Config::get('app.contact_email', '');
?>
<!DOCTYPE html>
<html lang="<?= e(Lang::locale()) ?>" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($pageTitle ? $pageTitle . ' · ' . __('ui.app_name') : __('ui.app_name')) ?></title>
    <meta name="theme-color" content="#060a18">
    <link rel="icon" href="<?= e(url('/assets/brand/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(url('/assets/vendor/fonts/plus-jakarta-sans-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e($asset('/assets/app.css')) ?>">
    <link rel="stylesheet" href="<?= e($asset('/assets/landing.css')) ?>">
    <link rel="stylesheet" href="<?= e($asset('/assets/public.css')) ?>">
    <script src="<?= e($asset('/assets/public.js')) ?>" defer></script>
</head>
<body class="landing public">

<header class="landing-bar">
    <a class="brand" href="<?= e(url($creator ? '/dashboard' : '/')) ?>">
        <?php require __DIR__ . '/partials/logo.php'; ?>
        <span class="brand-word">Stream<b>Org</b></span>
    </a>
    <div class="landing-bar-actions">
        <?php require __DIR__ . '/partials/language_switch.php'; ?>
        <?php if ($viewer !== null): ?>
            <span class="pub-who">
                <?php if (!empty($viewer['avatar_url'])): ?><img src="<?= e($viewer['avatar_url']) ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
                <a href="<?= e(url('/prizes')) ?>"><?= e($viewer['display_name'] ?: $viewer['twitch_login']) ?></a>
                <form method="post" action="<?= e(url('/viewer/logout')) ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="pub-linkish"><?= e(__('ui.action.logout')) ?></button>
                </form>
            </span>
        <?php elseif ($creator !== null): ?>
            <a class="lp-btn ghost small" href="<?= e(url('/dashboard')) ?>"><?= e(__('ui.landing.open_app')) ?></a>
        <?php endif; ?>
    </div>
</header>

<main class="pub-main">
    <?php foreach (take_flashes() as $flash): ?>
        <div class="pub-flash pub-flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<footer class="lp-footer">
    <span>© <?= date('Y') ?> StreamOrg</span>
    <a href="<?= e(url('/privacy')) ?>"><?= e(__('ui.privacy.title')) ?></a>
    <?php if ($email !== ''): ?>
        <a class="lp-footer-mail" href="mailto:<?= e($email) ?>"><?= e($email) ?></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
</footer>

</body>
</html>
