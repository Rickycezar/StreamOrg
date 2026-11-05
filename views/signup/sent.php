<?php
/**
 * After signing up: check the inbox, then wait for approval.
 *
 * @var string $email
 */
?>
<div class="login-shell">
    <div class="login-card">
        <?php $class = 'login-mark'; $w = 84; $h = 70; require dirname(__DIR__) . '/partials/logo.php'; ?>
        <h1><?= e(__('ui.signup.sent_title')) ?></h1>
        <p class="login-intro"><?= e(sprintf(__('ui.signup.sent_text'), $email)) ?></p>
        <ol class="signup-steps muted">
            <li><?= e(__('ui.signup.step_confirm')) ?></li>
            <?php if (!Signups::autoApprove()): ?>
                <li><?= e(__('ui.signup.step_approval')) ?></li>
                <li><?= e(__('ui.signup.step_login')) ?></li>
            <?php else: ?>
                <li><?= e(__('ui.signup.step_login_open')) ?></li>
            <?php endif; ?>
        </ol>
        <a class="btn wide" href="<?= e(url('/login')) ?>"><?= e(__('ui.signup.back_login')) ?></a>
    </div>
</div>
