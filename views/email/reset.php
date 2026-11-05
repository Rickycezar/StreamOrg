<?php
/**
 * Choosing a new password from an e-mail's link (a reset, or the first
 * one from the welcome e-mail). Over a private vault, the keys in it are
 * lost and the person must say they understand.
 *
 * @var string $token @var bool $welcome @var bool $private @var array $user
 */
?>
<div class="login-shell">
    <div class="login-card">
        <?php $class = 'login-mark'; $w = 84; $h = 70; require dirname(__DIR__) . '/partials/logo.php'; ?>
        <h1><?= e(__($welcome ? 'ui.email_page.welcome_title' : 'ui.email_page.reset_title')) ?></h1>
        <form method="post" action="<?= e(url('/password/reset')) ?>" data-turbo="false">
            <?= Csrf::field() ?>
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <p class="muted login-intro"><?= e(sprintf(__($welcome ? 'ui.email_page.welcome_intro' : 'ui.email_page.reset_intro'), $user['username'])) ?></p>
            <label>
                <span><?= e(__('ui.field.new_password')) ?></span>
                <input type="password" name="new_password" autocomplete="new-password" minlength="10" required autofocus>
            </label>
            <label>
                <span><?= e(__('ui.field.confirm_password')) ?></span>
                <input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required>
            </label>
            <?php if ($private): ?>
                <div class="notice">
                    <p><?= e(__('ui.email_page.reset_private_warning')) ?></p>
                    <label class="inline">
                        <input type="checkbox" name="accept_loss" value="1" required>
                        <span><?= e(__('ui.email_page.reset_private_accept')) ?></span>
                    </label>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn primary wide"><?= e(__('ui.email_page.reset_save')) ?></button>
        </form>
    </div>
</div>
