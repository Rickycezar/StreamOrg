<?php
/**
 * The giveaway form, for creating one and for editing it.
 *
 * @var ?array $g        the giveaway being edited, null for a new one
 * @var array  $content  planned and live content it can be linked to
 * @var string $formId
 */
$g = $g ?? null;
$v = static fn (string $key, string $default = ''): string => (string) ($g[$key] ?? $default);
$local = static fn (?string $at): string => $at ? substr(str_replace(' ', 'T', $at), 0, 16) : '';
?>
<form id="<?= e($formId) ?>" method="post" action="<?= e(url($g ? '/giveaways/update' : '/giveaways')) ?>" class="subform hidden" autocomplete="off">
    <?= Csrf::field() ?>
    <?php if ($g): ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>"><?php endif; ?>
    <div class="grid">
        <label class="grow">
            <span><?= e(__('ui.field.giveaway_name')) ?></span>
            <input type="text" name="title" value="<?= e($v('title')) ?>" maxlength="120" required placeholder="<?= e(__('ui.label.giveaway_name_hint')) ?>">
        </label>
        <label>
            <span><?= e(__('ui.field.keyword')) ?></span>
            <input type="text" name="keyword" value="<?= e($v('keyword')) ?>" required pattern="[a-z0-9_]{2,20}" maxlength="20" placeholder="mega">
            <small class="muted"><?= e(__('ui.label.keyword_hint')) ?></small>
        </label>
    </div>
    <label>
        <span><?= e(__('ui.field.rules')) ?></span>
        <textarea name="rules_note" rows="3" maxlength="1000" placeholder="<?= e(__('ui.label.rules_hint')) ?>"><?= e($v('rules_note')) ?></textarea>
    </label>
    <div class="grid">
        <label>
            <span><?= e(__('ui.field.entries_open')) ?></span>
            <input type="datetime-local" name="starts_at" value="<?= e($local($g['starts_at'] ?? null)) ?>">
        </label>
        <label>
            <span><?= e(__('ui.field.entries_close')) ?></span>
            <input type="datetime-local" name="ends_at" value="<?= e($local($g['ends_at'] ?? null)) ?>">
        </label>
        <label>
            <span><?= e(__('ui.field.claim_days')) ?></span>
            <input type="number" name="claim_days" min="1" max="365" value="<?= e($v('claim_days', '30')) ?>" required>
        </label>
    </div>
    <div class="grid">
        <label>
            <span><?= e(__('ui.field.entry_method')) ?></span>
            <select name="entry_method">
                <?php foreach (Giveaways::ENTRY_METHODS as $method): ?>
                    <option value="<?= e($method) ?>" <?= $v('entry_method', 'chat') === $method ? 'selected' : '' ?>><?= e(code_label('entry_method', $method)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= e(__('ui.field.winner_mode')) ?></span>
            <select name="winner_mode">
                <?php foreach (Giveaways::WINNER_MODES as $mode): ?>
                    <option value="<?= e($mode) ?>" <?= $v('winner_mode', 'pick') === $mode ? 'selected' : '' ?>><?= e(code_label('winner_mode', $mode)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="grow">
            <span><?= e(__('ui.field.during_content')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
            <select name="stream_id">
                <option value=""><?= e(__('ui.label.no_content')) ?></option>
                <?php foreach ($content as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) ($g['stream_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e(($c['scheduled_start'] ? fmt_datetime($c['scheduled_start'], 'd/m H:i') . ' · ' : '') . mb_strimwidth($c['title'], 0, 60, '…')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <label class="inline">
        <input type="checkbox" name="is_surprise" value="1" <?= !empty($g['is_surprise']) ? 'checked' : '' ?>>
        <span><?= e(__('ui.field.is_surprise')) ?> <small class="muted"><?= e(__('ui.label.surprise_hint')) ?></small></span>
    </label>
    <label>
        <span><?= e(__('ui.field.notes')) ?> <small class="muted"><?= e(__('ui.label.private_notes')) ?></small></span>
        <input type="text" name="notes" value="<?= e($v('notes')) ?>" maxlength="500">
    </label>
    <div class="card-actions">
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
        <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
    </div>
</form>
