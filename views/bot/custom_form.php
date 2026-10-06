<?php
/**
 * A streamer's own command, new or existing, as a hidden form the page
 * opens in a modal.
 *
 * @var string $formId @var ?array $command null for a new one @var string $prefix
 */
$command ??= null;
$value = static fn (string $key, mixed $default) => $command[$key] ?? $default;
?>
<form id="<?= e($formId) ?>" method="post" action="<?= e(url('/profile/bot/custom')) ?>" class="subform hidden">
    <?= Csrf::field() ?>
    <?php if ($command !== null): ?><input type="hidden" name="id" value="<?= (int) $command['id'] ?>"><?php endif; ?>
    <div class="grid">
        <label>
            <span><?= e(__('ui.field.bot_trigger')) ?></span>
            <span class="input-prefix"><span><?= e($prefix) ?></span>
                <input type="text" name="trigger" value="<?= e((string) $value('trigger', '')) ?>" required maxlength="25" pattern="[a-z0-9_]{1,25}" autocomplete="off" placeholder="discord">
            </span>
        </label>
        <label>
            <span><?= e(__('ui.field.bot_permission')) ?></span>
            <select name="permission">
                <?php foreach (ChatBot::PERMISSIONS as $permission): ?>
                    <option value="<?= e($permission) ?>" <?= $value('permission', 'everyone') === $permission ? 'selected' : '' ?>><?= e(__('ui.bot_permission.' . $permission)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= e(__('ui.field.bot_cooldown')) ?></span>
            <span class="input-suffix">
                <input type="number" name="cooldown_seconds" value="<?= (int) $value('cooldown_seconds', 10) ?>" min="0" max="<?= ChatBot::COOLDOWN_MAX ?>">
                <span class="muted"><?= e(__('ui.label.unit_seconds')) ?></span>
            </span>
        </label>
    </div>
    <label>
        <span><?= e(__('ui.field.bot_response')) ?></span>
        <textarea name="response" rows="2" required maxlength="<?= ChatBot::RESPONSE_MAX ?>"><?= e((string) $value('response', '')) ?></textarea>
        <small class="muted"><?= e(sprintf(__('ui.label.bot_placeholders'), '{user} {channel} {target}')) ?> · <?= e(__('ui.message.bot_target_hint')) ?></small>
    </label>
    <label class="inline">
        <input type="checkbox" name="is_enabled" value="1" <?= $value('is_enabled', true) ? 'checked' : '' ?>>
        <span><?= e(__('ui.field.enabled')) ?></span>
    </label>
    <div class="card-actions">
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
        <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
    </div>
</form>
