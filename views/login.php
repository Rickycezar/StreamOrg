<?php /** @var string $username */ ?>
<form class="card narrow" method="post" action="<?= e(url('/login')) ?>" data-turbo="false">
    <?= Csrf::field() ?>
    <?php $class = 'login-mark'; $w = 96; $h = 80; require dirname(__DIR__) . '/views/partials/logo.php'; ?>
    <h1><?= e(__('ui.app_name')) ?></h1>
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

    <?php require dirname(__DIR__) . '/views/partials/language_switch.php'; ?>
</form>
