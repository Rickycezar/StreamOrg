<?php
/**
 * Administration → E-mails: the heading, the mode it is in, and the tabs.
 *
 * @var string $tab
 */
$mode = Mailer::mode();
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.emails')) ?> <span class="badge email-mode email-mode-<?= e($mode) ?>"><?= e(__('ui.email_admin.mode_' . $mode)) ?></span></h1>
</div>
<nav class="admin-tabs" aria-label="<?= e(__('ui.nav.emails')) ?>">
    <?php foreach (EmailAdminController::TABS as $path => $label): ?>
        <a href="<?= e(url($path)) ?>" class="<?= $tab === $path ? 'active' : '' ?>" <?= $tab === $path ? 'aria-current="page"' : '' ?>><?= e(__($label)) ?></a>
    <?php endforeach; ?>
</nav>
