<?php
/**
 * Every e-mail StreamOrg queued, newest first, by state; each opens as it
 * would arrive. Held ones (preview mode) and failed ones can be dropped.
 *
 * @var string $tab @var list<array> $rows @var array<string, int> $counts @var ?string $status @var string $mode
 */
require __DIR__ . '/_tabs.php';
?>
<p class="muted"><?= e(__('ui.email_admin.outbox_intro_' . $mode)) ?></p>

<section class="tiles">
    <a class="tile <?= $status === null ? 'active' : '' ?>" href="<?= e(url('/admin/emails/outbox')) ?>">
        <span class="tile-value"><?= array_sum($counts) ?></span><span class="tile-label"><?= e(__('ui.email_admin.status_all')) ?></span>
    </a>
    <?php foreach (['held', 'queued', 'sent', 'failed', 'suppressed'] as $s): ?>
        <a class="tile <?= $status === $s ? 'active' : '' ?>" href="<?= e(url('/admin/emails/outbox?status=' . $s)) ?>">
            <span class="tile-value <?= $s === 'failed' && !empty($counts[$s]) ? 'warn' : '' ?>"><?= (int) ($counts[$s] ?? 0) ?></span>
            <span class="tile-label"><?= e(__('ui.email_admin.status_' . $s)) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.email_admin.tab_outbox')) ?></h2>
        <?php if (!empty($counts['held']) || !empty($counts['failed']) || !empty($counts['suppressed'])): ?>
            <form method="post" action="<?= e(url('/admin/emails/discard')) ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn small ghost" data-confirm="<?= e(__('ui.email_admin.discard_confirm')) ?>"><?= e(__('ui.email_admin.discard_all')) ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($rows === []): ?>
        <p class="empty"><?= e(__('ui.email_admin.outbox_empty')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('ui.email_admin.col_when')) ?></th>
                    <th><?= e(__('ui.email_admin.col_to')) ?></th>
                    <th><?= e(__('ui.email_admin.col_message')) ?></th>
                    <th><?= e(__('ui.field.status')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="nowrap" data-label="<?= e(__('ui.email_admin.col_when')) ?>"><?= e(fmt_datetime($r['created_at'], 'd/m H:i')) ?></td>
                        <td data-label="<?= e(__('ui.email_admin.col_to')) ?>"><?= e($r['to_address']) ?><?php if ($r['username']): ?> <small class="muted"><?= e($r['username']) ?></small><?php endif; ?></td>
                        <td data-card-title data-label="<?= e(__('ui.email_admin.col_message')) ?>">
                            <a href="<?= e(url('/admin/emails/message?id=' . (int) $r['id'])) ?>"><?= e($r['subject']) ?></a>
                            <small class="muted"><?= e(__('ui.email_admin.code_' . $r['template'])) ?> · <?= e($r['locale']) ?></small>
                        </td>
                        <td data-label="<?= e(__('ui.field.status')) ?>">
                            <span class="badge email-status-<?= e($r['status']) ?>"><?= e(__('ui.email_admin.status_' . $r['status'])) ?></span>
                            <?php if ($r['last_error']): ?><small class="muted" title="<?= e($r['last_error']) ?>"><?= e(mb_strimwidth($r['last_error'], 0, 50, '…')) ?></small><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
