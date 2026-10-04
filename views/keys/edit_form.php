<?php
/** One key's edit form, fetched when its Edit button is first used rather
 *  than rendered for every key up front (see KeysController::editForm).
 *  @var array $key @var array $statuses @var array $sources @var array $gamePlatforms */
?>
<form class="inline-edit" autocomplete="off" data-endpoint="/keys/update" data-id="<?= (int) $key['id'] ?>">
    <div class="grid">
        <label class="grow">
            <span><?= e(__('ui.field.key_code')) ?></span>
            <span class="keyfield">
                <input type="text" name="key_code" class="key-field key-code-input masked"
                       autocomplete="off" spellcheck="false"
                       placeholder="<?= e(__('ui.label.leave_blank_unchanged')) ?>"
                       data-id="<?= (int) $key['id'] ?>">
                <button type="button" class="btn small key-peek"
                        title="<?= e(__('ui.action.peek_hint')) ?>"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1.6 12S5.4 5.2 12 5.2 22.4 12 22.4 12 18.6 18.8 12 18.8 1.6 12 1.6 12Z"/><circle cx="12" cy="12" r="3.1"/></svg></button>
                <button type="button" class="btn small key-load" data-id="<?= (int) $key['id'] ?>"
                        title="<?= e(__('ui.action.load_key')) ?>"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.4 12a8.4 8.4 0 1 1-2.5-5.9"/><path d="M20.4 3.6v5h-5"/></svg></button>
                <button type="button" class="btn small key-copy"
                        title="<?= e(__('ui.action.copy')) ?>"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11.5" height="11.5" rx="2.2"/><path d="M5.5 15.5A2 2 0 0 1 3.5 13.5v-8a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2"/></svg></button>
            </span>
        </label>
        <label>
            <span><?= e(__('ui.field.key_type')) ?></span>
            <select name="key_type">
                <?php foreach (['common', 'review'] as $type): ?>
                    <option value="<?= e($type) ?>" <?= $key['key_type'] === $type ? 'selected' : '' ?>>
                        <?= e(code_label('key_type', $type)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= e(__('ui.field.content_type')) ?></span>
            <select name="content_type">
                <?php foreach (['game', 'dlc'] as $type): ?>
                    <option value="<?= e($type) ?>" <?= $key['content_type'] === $type ? 'selected' : '' ?>>
                        <?= e(code_label('content_type', $type)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="grow">
            <span><?= e(__('ui.field.game')) ?></span>
            <select name="game_id" data-picker="games">
                <option value="<?= (int) $key['game_id'] ?>" selected><?= e($key['game_title']) ?></option>
            </select>
        </label>
        <label>
            <span><?= e(__('ui.field.source')) ?></span>
            <select name="key_platform">
                <?php foreach ($sources as $src): ?>
                    <option value="<?= e($src) ?>" <?= $key['key_platform_code'] === $src ? 'selected' : '' ?>>
                        <?= e(code_label('key_platform', $src)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= e(__('ui.field.platform')) ?></span>
            <select name="game_platform">
                <?php foreach ($gamePlatforms as $gp): ?>
                    <option value="<?= e($gp) ?>" <?= $key['game_platform_code'] === $gp ? 'selected' : '' ?>>
                        <?= e(code_label('game_platform', $gp)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= e(__('ui.field.status')) ?></span>
            <select name="status"
                    <?= $key['under_embargo'] ? 'data-embargo="' . e(fmt_datetime($key['embargo_until'])) . '" data-game="' . e($key['game_title']) . '"' : '' ?>>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $key['status'] === $status ? 'selected' : '' ?>>
                        <?= e(code_label('key_status', $status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= e(__('ui.field.redeem_by')) ?></span>
            <input type="datetime-local" name="expires_at"
                   value="<?= e($key['expires_at'] ? substr(str_replace(' ', 'T', $key['expires_at']), 0, 16) : '') ?>">
        </label>
        <label>
            <span><?= e(__('ui.field.region')) ?></span>
            <input type="text" name="region" value="<?= e($key['region'] ?? '') ?>">
        </label>
    </div>
    <label>
        <span><?= e(__('ui.field.notes')) ?></span>
        <input type="text" name="notes" value="<?= e($key['notes'] ?? '') ?>">
    </label>
    <div class="editrow-actions">
        <button type="submit" class="btn primary small"><?= e(__('ui.action.save')) ?></button>
        <button type="button" class="btn small row-cancel"><?= e(__('ui.action.cancel')) ?></button>
        <span class="edit-result muted small"></span>
        <button type="button" class="btn small danger-btn row-delete"
                data-endpoint="/keys/delete" data-id="<?= (int) $key['id'] ?>"
                data-label="<?= e($key['game_title']) ?>"><?= e(__('ui.action.delete')) ?></button>
    </div>
</form>
