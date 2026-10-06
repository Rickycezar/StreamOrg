<?php
/**
 * "How it works": a page's guide in plain words, section by section, each
 * with a small drawing. A document of its own, meant for a small separate
 * window (like the vault security explainer), so it has no menu.
 *
 * @var string $page @var list<string> $sections @var ?array $user
 */
$asset = static fn (string $path): string => url($path) . '?v=' . (@filemtime(dirname(__DIR__) . '/public' . $path) ?: 0);
$t     = static fn (string $key): string => __('ui.help.' . $page . '.' . $key);
$lines = static fn (string $text): array => array_values(array_filter(array_map('trim', explode("\n", $text))));
?>
<!DOCTYPE html>
<html lang="<?= e(Lang::locale()) ?>" <?= Themes::htmlAttributes($user) ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($t('title')) ?> · <?= e(__('ui.app_name')) ?></title>
    <link rel="icon" href="<?= e(url('/assets/brand/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e($asset('/assets/app.css')) ?>">
    <script src="<?= e($asset('/assets/theme.js')) ?>"></script>
    <script src="<?= e($asset('/assets/popup.js')) ?>" defer></script>
</head>
<body class="sec-page help-page">
<main class="sec help">
    <header class="sec-hero">
        <span class="sec-shield help-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.6 9.2a2.5 2.5 0 0 1 4.8.8c0 1.7-2.4 2.2-2.4 3.5M12 16.8h.01"/></svg>
        </span>
        <div>
            <span class="help-eyebrow"><?= e(__('ui.help.eyebrow')) ?></span>
            <h1><?= e($t('title')) ?></h1>
            <p class="sec-lead"><?= e($t('lead')) ?></p>
        </div>
        <button type="button" class="btn small sec-close hidden" data-close-window><?= e(__('ui.action.close')) ?></button>
    </header>

    <?php foreach ($sections as $i => $section): ?>
        <section class="help-step<?= $i % 2 === 1 ? ' flip' : '' ?>">
            <figure class="help-art" aria-hidden="true"><?= View::partial('help/art', ['art' => $page . '.' . $section]) ?></figure>
            <div class="help-words">
                <span class="help-num"><?= $i + 1 ?></span>
                <h2><?= e($t($section . '_title')) ?></h2>
                <?php foreach ($lines($t($section . '_text')) as $j => $line): ?>
                    <?php if ($j === 0): ?>
                        <p><?= e($line) ?></p>
                    <?php else: ?>
                        <?= $j === 1 ? '<ul class="help-points">' : '' ?><li><?= e($line) ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?= count($lines($t($section . '_text'))) > 1 ? '</ul>' : '' ?>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="help-tips">
        <h2><?= e(__('ui.help.tips_title')) ?></h2>
        <ul>
            <?php foreach ($lines($t('tips')) as $tip): ?>
                <li><?= e($tip) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</main>
</body>
</html>
