<?php /** @var string $username */ ?>
<div class="login-shell">
    <form class="login-card" method="post" action="<?= e(url('/login')) ?>" data-turbo="false">
        <a class="login-back" href="<?= e(url('/')) ?>" data-turbo="false">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            <?= e(__('ui.action.back')) ?>
        </a>

        <?= Csrf::field() ?>
        <?php $class = 'login-mark'; $w = 84; $h = 70; require dirname(__DIR__) . '/views/partials/logo.php'; ?>
        <h1><?= e(__('ui.login.title')) ?></h1>
        <p class="muted"><?= e(__('ui.message.login_intro')) ?></p>

        <label>
            <span><?= e(__('ui.field.username')) ?></span>
            <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" autofocus required>
        </label>

        <label>
            <span><?= e(__('ui.field.password')) ?></span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>

        <button type="submit" class="btn primary wide"><?= e(__('ui.action.login')) ?></button>

        <footer class="login-foot">
            <?php require dirname(__DIR__) . '/views/partials/language_switch.php'; ?>
        </footer>
    </form>
</div>
