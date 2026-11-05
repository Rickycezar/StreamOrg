<?php
/**
 * One e-mail of the outbox, as it was rendered when queued: who it is
 * for, what became of it, and its HTML and plain-text versions.
 *
 * @var string $tab @var array $row
 */
require __DIR__ . '/_tabs.php';
?>
<p class="muted small"><a href="<?= e(url('/admin/emails/outbox')) ?>">← <?= e(__('ui.email_admin.tab_outbox')) ?></a></p>

<div class="email-editor">
    <section class="card">
        <h2><?= e($row['subject']) ?></h2>
        <dl class="email-meta">
            <div><dt><?= e(__('ui.email_admin.col_to')) ?></dt><dd><?= e($row['to_address']) ?><?php if ($row['username']): ?> <small class="muted"><?= e($row['username']) ?></small><?php endif; ?></dd></div>
            <div><dt><?= e(__('ui.email_admin.col_message')) ?></dt><dd><?= e(__('ui.email_admin.code_' . $row['template'])) ?> · <?= e($row['locale']) ?></dd></div>
            <div><dt><?= e(__('ui.field.status')) ?></dt><dd><span class="badge email-status-<?= e($row['status']) ?>"><?= e(__('ui.email_admin.status_' . $row['status'])) ?></span></dd></div>
            <div><dt><?= e(__('ui.email_admin.col_when')) ?></dt><dd><?= e(fmt_datetime($row['created_at'])) ?><?= $row['sent_at'] ? ' → ' . e(fmt_datetime($row['sent_at'])) : '' ?></dd></div>
            <?php if ($row['attempts']): ?><div><dt><?= e(__('ui.email_admin.attempts')) ?></dt><dd><?= (int) $row['attempts'] ?></dd></div><?php endif; ?>
            <?php if ($row['last_error']): ?><div><dt><?= e(__('ui.email_admin.last_error')) ?></dt><dd><?= e($row['last_error']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if (in_array($row['status'], ['held', 'failed', 'suppressed'], true)): ?>
            <form method="post" action="<?= e(url('/admin/emails/discard')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <button type="submit" class="btn small ghost"><?= e(__('ui.email_admin.discard_one')) ?></button>
            </form>
        <?php endif; ?>
        <h3 class="section-head"><?= e(__('ui.email_admin.preview_text')) ?></h3>
        <pre class="email-text"><?= e((string) $row['body_text']) ?></pre>
    </section>
    <section class="card email-preview">
        <h2><?= e(__('ui.email_admin.preview')) ?></h2>
        <iframe class="email-frame" title="<?= e(__('ui.email_admin.preview')) ?>" sandbox="" srcdoc="<?= e((string) $row['html']) ?>"></iframe>
    </section>
</div>
