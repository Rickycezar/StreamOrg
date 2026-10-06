<?php
/**
 * Chat bot → Timed messages: what the bot posts on its own while the
 * streamer is live.
 *
 * @var list<array> $timers
 */
?>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.bot_timers')) ?> (<?= count($timers) ?>)</h2>
        <?php if (count($timers) < ChatBot::TIMERS_MAX): ?>
            <button type="button" class="btn primary" data-modal-form="#timer-new"
                    data-modal-title="<?= e(__('ui.action.bot_add_timer')) ?>"><?= e(__('ui.action.bot_add_timer')) ?></button>
        <?php endif; ?>
    </div>
    <p class="muted small"><?= e(__('ui.message.bot_timers_hint')) ?></p>

    <?php if ($timers === []): ?>
        <p class="empty"><?= e(__('ui.message.bot_no_timers')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('ui.field.bot_timer_message')) ?></th>
                    <th><?= e(__('ui.label.bot_timer_rule')) ?></th>
                    <th><?= e(__('ui.label.bot_timer_last')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($timers as $timer): ?>
                    <tr>
                        <td class="bot-reply">
                            <?= e(mb_strimwidth($timer['message'], 0, 90, '…')) ?>
                            <?php if (!$timer['is_enabled']): ?> <span class="badge off"><?= e(__('ui.label.off')) ?></span><?php endif; ?>
                        </td>
                        <td class="nowrap"><?= e(sprintf(__('ui.label.bot_timer_rule_value'), (int) $timer['interval_minutes'], (int) $timer['min_messages'])) ?></td>
                        <td><?= $timer['last_sent_at'] ? e(fmt_datetime($timer['last_sent_at'])) : '—' ?></td>
                        <td class="rowactions">
                            <button type="button" class="btn small" data-modal-form="#timer-<?= (int) $timer['id'] ?>"
                                    data-modal-title="<?= e(__('ui.label.bot_timers')) ?>"><?= e(__('ui.action.edit')) ?></button>
                            <form method="post" action="<?= e(url('/profile/bot/timer/delete')) ?>">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int) $timer['id'] ?>">
                                <button type="submit" class="btn small danger-btn" data-confirm="<?= e(__('ui.message.bot_delete_timer')) ?>"><?= e(__('ui.action.delete')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?= View::partial('bot/timer_form', ['formId' => 'timer-new', 'timer' => null]) ?>
<?php foreach ($timers as $timer): ?>
    <?= View::partial('bot/timer_form', ['formId' => 'timer-' . (int) $timer['id'], 'timer' => $timer]) ?>
<?php endforeach; ?>
