<?php
/**
 * A timed message, new or existing, as a hidden form the page opens in a
 * modal.
 *
 * @var string $formId @var ?array $timer null for a new one
 */
$timer ??= null;
$value = static fn (string $key, mixed $default) => $timer[$key] ?? $default;
?>
<form id="<?= e($formId) ?>" method="post" action="<?= e(url('/bot/timer')) ?>" class="subform hidden">
    <?= Csrf::field() ?>
    <?php if ($timer !== null): ?><input type="hidden" name="id" value="<?= (int) $timer['id'] ?>"><?php endif; ?>
    <label>
        <span><?= e(__('ui.field.bot_timer_message')) ?></span>
        <textarea name="message" rows="3" required maxlength="<?= ChatBot::RESPONSE_MAX ?>" placeholder="<?= e(__('ui.label.bot_timer_example')) ?>"><?= e((string) $value('message', '')) ?></textarea>
        <small class="muted"><?= e(sprintf(__('ui.label.bot_placeholders'), '{channel}')) ?></small>
    </label>
    <div class="grid">
        <label>
            <span><?= e(__('ui.field.bot_timer_interval')) ?></span>
            <span class="input-suffix">
                <input type="number" name="interval_minutes" value="<?= (int) $value('interval_minutes', 15) ?>" required
                       min="<?= ChatBot::TIMER_MINUTES_MIN ?>" max="<?= ChatBot::TIMER_MINUTES_MAX ?>">
                <span class="muted"><?= e(__('ui.label.unit_minutes')) ?></span>
            </span>
            <small class="muted"><?= e(__('ui.message.bot_timer_interval_hint')) ?></small>
        </label>
        <label>
            <span><?= e(__('ui.field.bot_timer_messages')) ?></span>
            <span class="input-suffix">
                <input type="number" name="min_messages" value="<?= (int) $value('min_messages', 5) ?>" required
                       min="0" max="<?= ChatBot::TIMER_MESSAGES_MAX ?>">
                <span class="muted"><?= e(__('ui.label.bot_chat_messages')) ?></span>
            </span>
            <small class="muted"><?= e(__('ui.message.bot_timer_messages_hint')) ?></small>
        </label>
    </div>
    <label class="inline">
        <input type="checkbox" name="is_enabled" value="1" <?= $value('is_enabled', true) ? 'checked' : '' ?>>
        <span><?= e(__('ui.field.enabled')) ?></span>
    </label>
    <div class="card-actions">
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
        <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
    </div>
</form>
