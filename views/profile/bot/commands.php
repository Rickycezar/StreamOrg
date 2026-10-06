<?php
/**
 * Chat bot → Commands: the built-in ones (made the streamer's own, or the
 * defaults) and the streamer's custom commands.
 *
 * @var string $prefix @var array<string, array> $commands @var list<array> $custom
 */
?>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.bot_custom_commands')) ?> (<?= count($custom) ?>)</h2>
        <?php if (count($custom) < ChatBot::CUSTOM_MAX): ?>
            <button type="button" class="btn primary" data-modal-form="#custom-new"
                    data-modal-title="<?= e(__('ui.action.bot_add_command')) ?>"><?= e(__('ui.action.bot_add_command')) ?></button>
        <?php endif; ?>
    </div>
    <p class="muted small"><?= e(__('ui.message.bot_custom_hint')) ?></p>

    <?php if ($custom === []): ?>
        <p class="empty"><?= e(__('ui.message.bot_no_custom')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('ui.field.bot_trigger')) ?></th>
                    <th><?= e(__('ui.field.bot_response')) ?></th>
                    <th><?= e(__('ui.field.bot_permission')) ?></th>
                    <th><?= e(__('ui.field.bot_cooldown')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($custom as $command): ?>
                    <tr class="<?= $command['is_enabled'] ? '' : 'muted' ?>">
                        <td><code><?= e($prefix . $command['trigger']) ?></code><?php if (!$command['is_enabled']): ?> <span class="badge off"><?= e(__('ui.label.off')) ?></span><?php endif; ?></td>
                        <td class="bot-reply"><?= e(mb_strimwidth($command['response'], 0, 90, '…')) ?></td>
                        <td><?= e(__('ui.bot_permission.' . $command['permission'])) ?></td>
                        <td><?= (int) $command['cooldown_seconds'] ?> s</td>
                        <td class="rowactions">
                            <button type="button" class="btn small" data-modal-form="#custom-<?= (int) $command['id'] ?>"
                                    data-modal-title="<?= e($prefix . $command['trigger']) ?>"><?= e(__('ui.action.edit')) ?></button>
                            <form method="post" action="<?= e(url('/profile/bot/custom/delete')) ?>">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int) $command['id'] ?>">
                                <button type="submit" class="btn small danger-btn" data-confirm="<?= e(sprintf(__('ui.message.bot_delete_command'), $prefix . $command['trigger'])) ?>"><?= e(__('ui.action.delete')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?= View::partial('bot/custom_form', ['formId' => 'custom-new', 'command' => null, 'prefix' => $prefix]) ?>
<?php foreach ($custom as $command): ?>
    <?= View::partial('bot/custom_form', ['formId' => 'custom-' . (int) $command['id'], 'command' => $command, 'prefix' => $prefix]) ?>
<?php endforeach; ?>

<section>
    <h2><?= e(__('ui.label.bot_builtin_commands')) ?></h2>
    <p class="muted small"><?= e(__('ui.message.bot_commands_hint')) ?></p>
    <?php foreach ($commands as $code => $command): ?>
        <?= View::partial('bot/command_form', [
            'action'   => '/profile/bot/command',
            'code'     => $code,
            'command'  => $command,
            'prefix'   => $prefix,
            'personal' => (bool) $command['personal'],
            'streamer' => true,
        ]) ?>
    <?php endforeach; ?>
</section>
