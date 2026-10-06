<?php
/**
 * What the bot has recorded so far.
 *
 * @var array{broadcasts:int, viewers:int, rows:int, last:?string, live:bool, bytes:?int} $stats
 */
?>
<dl class="detail">
    <dt><?= e(__('ui.label.bot_broadcasts')) ?></dt>
    <dd>
        <?= (int) $stats['broadcasts'] ?>
        <?php if ($stats['live']): ?><span class="badge ok"><?= e(__('ui.label.bot_recording')) ?></span><?php endif; ?>
        <?php if ($stats['last']): ?><small class="muted">· <?= e(sprintf(__('ui.label.bot_last_broadcast'), fmt_datetime($stats['last']))) ?></small><?php endif; ?>
    </dd>
    <dt><?= e(__('ui.label.bot_viewers')) ?></dt>
    <dd><?= (int) $stats['viewers'] ?></dd>
    <?php if ($stats['bytes'] !== null): ?>
        <dt><?= e(__('ui.label.bot_storage')) ?></dt>
        <dd><?= e(sprintf(__('ui.label.bot_storage_value'), number_format($stats['rows'], 0, ',', '.'), number_format($stats['bytes'] / 1048576, 1, ',', '.'))) ?></dd>
    <?php endif; ?>
</dl>
