<?php
/** @var array $giveaways @var array $content */
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.giveaways')) ?></h1>
    <button type="button" class="btn primary" data-new data-modal-form="#new-giveaway"
            data-modal-title="<?= e(__('ui.action.new_giveaway')) ?>"><?= e(__('ui.action.new_giveaway')) ?></button>
</div>
<p class="muted"><?= e(__('ui.message.giveaways_intro')) ?></p>

<?php $g = null; $formId = 'new-giveaway'; require __DIR__ . '/form.php'; ?>

<?php if ($giveaways === []): ?>
    <section class="card"><p class="empty"><?= e(__('ui.message.giveaways_empty')) ?></p></section>
<?php else: ?>
    <div class="giveaway-list">
        <?php foreach ($giveaways as $g): ?>
            <a class="card link giveaway-card status-<?= e($g['status']) ?>" href="<?= e(url('/giveaways/show?id=' . (int) $g['id'])) ?>">
                <span class="giveaway-card-head">
                    <strong><?= e($g['title']) ?></strong>
                    <span class="badge giveaway-status-<?= e($g['status']) ?>"><?= e(code_label('giveaway_status', $g['status'])) ?></span>
                </span>
                <span class="muted small">
                    <code>!<?= e(__('ui.label.join_command')) ?> <?= e((string) $g['keyword']) ?></code>
                    <?php if ($g['is_surprise']): ?> · <?= e(__('ui.label.surprise')) ?><?php endif; ?>
                    <?php if ($g['ends_at']): ?> · <?= e(sprintf(__('ui.label.until_date'), fmt_datetime($g['ends_at'], 'd/m H:i'))) ?><?php endif; ?>
                </span>
                <span class="giveaway-counts">
                    <span><b><?= (int) $g['claimed_count'] ?>/<?= (int) $g['prize_count'] ?></b> <?= e(__('ui.label.prizes_claimed')) ?></span>
                    <span><b><?= (int) $g['winner_count'] ?></b> <?= e(__('ui.label.winners')) ?></span>
                    <?php if ($g['entry_method'] === 'chat'): ?><span><b><?= (int) $g['entry_count'] ?></b> <?= e(__('ui.label.entries')) ?></span><?php endif; ?>
                </span>
                <?php if ($g['content_title']): ?>
                    <small class="muted"><?= e(__('ui.field.during_content')) ?>: <?= e(mb_strimwidth($g['content_title'], 0, 70, '…')) ?></small>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
