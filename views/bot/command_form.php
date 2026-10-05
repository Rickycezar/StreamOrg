<?php
/**
 * One chat command's form, shared by the admin's defaults and a streamer's
 * own version: trigger, reply, who may use it, cooldown, on/off.
 *
 * @var string $action @var string $code @var array $command @var string $prefix
 * @var bool $personal whether a streamer's own version exists (offers "back to default")
 * @var bool $streamer whether this is the streamer's form (not the admin's defaults)
 */
$placeholders = implode(' ', array_map(static fn (string $p): string => '{' . $p . '}', ChatBot::CODES[$code]));
?>
<form method="post" action="<?= e(url($action)) ?>" class="card bot-command">
    <?= Csrf::field() ?>
    <input type="hidden" name="code" value="<?= e($code) ?>">

    <div class="card-head">
        <h3><code><?= e($prefix . $command['trigger']) ?></code></h3>
        <span class="badge <?= $command['is_enabled'] ? 'ok' : 'off' ?>"><?= e(__($command['is_enabled'] ? 'ui.label.on' : 'ui.label.off')) ?></span>
        <?php if ($streamer): ?>
            <span class="badge"><?= e(__($personal ? 'ui.label.bot_personal' : 'ui.label.bot_default')) ?></span>
        <?php endif; ?>
    </div>
    <p class="muted small"><?= e(__('ui.bot_command.' . $code)) ?></p>

    <div class="grid">
        <label>
            <span><?= e(__('ui.field.bot_trigger')) ?></span>
            <span class="input-prefix"><span><?= e($prefix) ?></span>
                <input type="text" name="trigger" value="<?= e($command['trigger']) ?>" required maxlength="25" pattern="[a-z0-9_]{1,25}" autocomplete="off">
            </span>
        </label>
        <label>
            <span><?= e(__('ui.field.bot_permission')) ?></span>
            <select name="permission">
                <?php foreach (ChatBot::PERMISSIONS as $permission): ?>
                    <option value="<?= e($permission) ?>" <?= $command['permission'] === $permission ? 'selected' : '' ?>><?= e(__('ui.bot_permission.' . $permission)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= e(__('ui.field.bot_cooldown')) ?></span>
            <span class="input-suffix">
                <input type="number" name="cooldown_seconds" value="<?= (int) $command['cooldown_seconds'] ?>" min="0" max="<?= ChatBot::COOLDOWN_MAX ?>">
                <span class="muted"><?= e(__('ui.label.unit_seconds')) ?></span>
            </span>
        </label>
    </div>
    <label>
        <span><?= e(__('ui.field.bot_response')) ?></span>
        <textarea name="response" rows="2" required maxlength="<?= ChatBot::RESPONSE_MAX ?>"><?= e($command['response']) ?></textarea>
        <small class="muted"><?= e(sprintf(__('ui.label.bot_placeholders'), $placeholders)) ?></small>
    </label>
    <label class="inline">
        <input type="checkbox" name="is_enabled" value="1" <?= $command['is_enabled'] ? 'checked' : '' ?>>
        <span><?= e(__('ui.field.enabled')) ?></span>
    </label>

    <div class="card-actions">
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
        <?php if ($streamer && $personal): ?>
            <button type="submit" name="reset" value="1" class="btn" formnovalidate><?= e(__('ui.action.bot_reset_command')) ?></button>
        <?php endif; ?>
    </div>
</form>
