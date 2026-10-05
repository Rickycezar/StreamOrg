<?php
/**
 * Header of the viewer pages (claim, my prizes, privacy): the brand, and the
 * viewer signed in, if any.
 *
 * @var ?array $viewer
 */
$viewer = $viewer ?? Viewers::current();

if (Auth::check()) {
    return;
}
?>
<header class="viewer-bar">
    <a class="brand" href="<?= e(url('/')) ?>">
        <?php $class = 'brand-mark'; require __DIR__ . '/logo.php'; ?>
        <span class="brand-word">Stream<b>Org</b></span>
    </a>
    <?php if ($viewer !== null): ?>
        <span class="viewer-who">
            <?php if (!empty($viewer['avatar_url'])): ?><img src="<?= e($viewer['avatar_url']) ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
            <a href="<?= e(url('/prizes')) ?>"><?= e($viewer['display_name'] ?: $viewer['twitch_login']) ?></a>
            <?php if (Viewers::current() !== null): ?>
                <form method="post" action="<?= e(url('/viewer/logout')) ?>" data-turbo="false">
                    <?= Csrf::field() ?>
                    <button type="submit" class="linkish"><?= e(__('ui.action.logout')) ?></button>
                </form>
            <?php endif; ?>
        </span>
    <?php endif; ?>
</header>
