<?php
/**
 * "Forgot password": the username or address; a link goes to the
 * account's address, and the answer is the same either way.
 */
?>
<div class="login-shell">
    <div class="login-card">
        <a class="login-back" href="<?= e(url('/login')) ?>">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            <?= e(__('ui.action.back')) ?>
        </a>
        <?php $class = 'login-mark'; $w = 84; $h = 70; require dirname(__DIR__) . '/partials/logo.php'; ?>
        <h1><?= e(__('ui.email_page.forgot_title')) ?></h1>
        <form method="post" action="<?= e(url('/password/forgot')) ?>" data-turbo="false">
            <?= Csrf::field() ?>
            <p class="muted login-intro"><?= e(__('ui.email_page.forgot_intro')) ?></p>
            <label>
                <span><?= e(__('ui.email_page.forgot_who')) ?></span>
                <input type="text" name="who" autocomplete="username" autofocus required maxlength="254">
            </label>
            <button type="submit" class="btn primary wide"><?= e(__('ui.email_page.forgot_send')) ?></button>
            <p class="muted small login-note"><?= e(__('ui.email_page.forgot_vault_note')) ?></p>
        </form>
    </div>
</div>
