<?php
/**
 * Sign-in, as a creator (username and password) or as a viewer (Twitch).
 *
 * Creators cannot sign in through Twitch: their password also opens a
 * private key vault. Viewers have no password at all.
 *
 * @var string $username @var string $as 'creator' | 'viewer' @var ?array $viewer @var bool $twitch
 */
?>
<div class="login-shell">
    <div class="login-card">
        <a class="login-back" href="<?= e(url('/')) ?>" data-turbo="false">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            <?= e(__('ui.action.back')) ?>
        </a>

        <?php $class = 'login-mark'; $w = 84; $h = 70; require dirname(__DIR__) . '/views/partials/logo.php'; ?>
        <h1><?= e(__('ui.login.title')) ?></h1>

        <nav class="login-tabs" aria-label="<?= e(__('ui.login.who')) ?>">
            <a href="<?= e(url('/login')) ?>" class="<?= $as === 'creator' ? 'active' : '' ?>" <?= $as === 'creator' ? 'aria-current="page"' : '' ?>><?= e(__('ui.login.as_creator')) ?></a>
            <a href="<?= e(url('/login?as=viewer')) ?>" class="<?= $as === 'viewer' ? 'active' : '' ?>" <?= $as === 'viewer' ? 'aria-current="page"' : '' ?>><?= e(__('ui.login.as_viewer')) ?></a>
        </nav>

        <?php if ($as === 'creator'): ?>
            <form method="post" action="<?= e(url('/login')) ?>" data-turbo="false">
                <?= Csrf::field() ?>
                <p class="muted login-intro"><?= e(__('ui.login.creator_intro')) ?></p>

                <label>
                    <span><?= e(__('ui.field.username')) ?></span>
                    <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" autofocus required>
                </label>

                <label>
                    <span><?= e(__('ui.field.password')) ?></span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>

                <button type="submit" class="btn primary wide"><?= e(__('ui.action.login')) ?></button>
                <p class="muted small login-note"><?= e(__('ui.login.creator_no_twitch')) ?></p>
            </form>
        <?php else: ?>
            <div class="login-viewer">
                <p class="muted login-intro"><?= e(__('ui.login.viewer_intro')) ?></p>
                <?php if ($viewer !== null): ?>
                    <a class="btn primary wide" href="<?= e(url('/prizes')) ?>"><?= e(sprintf(__('ui.login.viewer_continue'), $viewer['display_name'] ?: $viewer['twitch_login'])) ?></a>
                <?php elseif ($twitch): ?>
                    <a class="btn wide twitch-signin" href="<?= e(url('/viewer/login?back=/prizes')) ?>" data-turbo="false">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3h16v11l-4 4h-4l-3 3v-3H4zM10 8v4M15 8v4"/></svg>
                        <?= e(__('ui.viewer.sign_in_twitch')) ?>
                    </a>
                <?php else: ?>
                    <p class="empty"><?= e(__('ui.message.twitch_not_configured')) ?></p>
                <?php endif; ?>
                <p class="muted small login-note"><?= e(__('ui.viewer.twitch_identity_only')) ?></p>
            </div>
        <?php endif; ?>

        <footer class="login-foot">
            <?php require dirname(__DIR__) . '/views/partials/language_switch.php'; ?>
            <a class="login-privacy" href="<?= e(url('/privacy')) ?>"><?= e(__('ui.privacy.title')) ?></a>
        </footer>
    </div>
</div>
