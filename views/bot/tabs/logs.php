<?php
/**
 * Chat bot → Logs: what the bot did in the streamer's channel, newest
 * first (kept for two weeks).
 *
 * @var list<array> $log
 */
?>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.bot_logs')) ?></h2>
        <span class="muted small"><?= e(__('ui.message.bot_logs_kept')) ?></span>
    </div>
    <?= View::partial('bot/log', ['log' => $log, 'showUser' => false]) ?>
</section>
