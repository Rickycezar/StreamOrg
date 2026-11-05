<?php
/**
 * Stopping notification e-mails from an e-mail's link: a button first
 * (opening the link alone changes nothing), then the confirmation.
 *
 * @var bool $done @var int $user @var string $sig
 */
?>
<div class="login-shell">
    <div class="login-card">
        <?php $class = 'login-mark'; $w = 84; $h = 70; require dirname(__DIR__) . '/partials/logo.php'; ?>
        <h1><?= e(__('ui.email_page.unsubscribe_title')) ?></h1>
        <?php if ($done): ?>
            <p class="muted login-intro"><?= e(__('ui.email_page.unsubscribe_done')) ?></p>
            <a class="btn primary wide" href="<?= e(url('/profile')) ?>"><?= e(__('ui.email_page.unsubscribe_profile')) ?></a>
        <?php else: ?>
            <form method="post" action="<?= e(url('/email/unsubscribe')) ?>" data-turbo="false">
                <input type="hidden" name="u" value="<?= (int) $user ?>">
                <input type="hidden" name="s" value="<?= e($sig) ?>">
                <p class="muted login-intro"><?= e(__('ui.email_page.unsubscribe_intro')) ?></p>
                <button type="submit" class="btn primary wide"><?= e(__('ui.email_page.unsubscribe_confirm')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
