<?php
/**
 * How the bot reads a channel's chat, and whether it can see who is in it.
 *
 * @var string $access 'permission', 'moderator', 'none' or 'unknown' @var ?bool $chattersOk
 */
$tone = match ($access) {
    'permission', 'moderator' => 'ok',
    'none'                    => 'warn',
    default                   => 'off',
};
?>
<span class="badge <?= $tone ?>"><?= e(__('ui.bot_access.' . $access)) ?></span>
<?php if ($chattersOk !== null && $access !== 'none'): ?>
    <span class="badge <?= $chattersOk ? 'ok' : 'off' ?>" title="<?= e(__($chattersOk ? 'ui.message.bot_chatters_ok' : 'ui.message.bot_chatters_missing')) ?>"><?= e(__($chattersOk ? 'ui.label.bot_watch_time' : 'ui.label.bot_messages_only')) ?></span>
<?php endif; ?>
