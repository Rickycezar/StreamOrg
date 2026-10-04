<?php
/** Unlock form for a private vault whose session copy is gone.
 *  @var string $back where to return: '/keys' or '/profile' */
?>
<div class="flash flash-error">
    <p><?= e(__('ui.message.vault_locked')) ?></p>
    <form method="post" action="<?= e(url('/vault/unlock')) ?>" class="subform" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <label>
            <span><?= e(__('ui.field.current_password')) ?></span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit" class="btn primary"><?= e(__('ui.action.unlock_vault')) ?></button>
    </form>
</div>
