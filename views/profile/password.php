<?php
/** @var array $user */
?>
<section class="card">
    <h2><?= e(__('ui.label.change_password')) ?></h2>
    <p class="muted small"><?= e(__('ui.message.password_rules')) ?></p>
    <p class="muted small"><?= e(__('ui.message.password_ends_sessions')) ?></p>
    <?php if ($user['vault_mode'] === 'private'): ?>
        <p class="muted small"><?= e(__('ui.message.vault_password_note')) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/profile/password')) ?>" class="subform" autocomplete="off">
        <?= Csrf::field() ?>
        <label>
            <span><?= e(__('ui.field.current_password')) ?></span>
            <input type="password" name="current_password" autocomplete="current-password" required>
        </label>
        <label>
            <span><?= e(__('ui.field.new_password')) ?></span>
            <input type="password" name="new_password" autocomplete="new-password" minlength="10" required>
        </label>
        <label>
            <span><?= e(__('ui.field.confirm_password')) ?></span>
            <input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required>
        </label>
        <button type="submit" class="btn primary"><?= e(__('ui.action.change_password')) ?></button>
    </form>
</section>
