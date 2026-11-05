<?php
/**
 * Keys → Add in batch: many games at once. Each row is one game with its
 * codes (one per line) and its own platform, source, type, status and
 * dates; a new row starts with the previous row's choices, so a batch
 * from one source only needs the games and the codes. The game field
 * finds games in the catalog, on Twitch and on Steam at once.
 * Saved together (KeysController::batchStore), with the problems shown
 * on their rows.
 *
 * @var list<string> $platforms @var list<string> $sources @var list<string> $statuses @var bool $vaultLocked
 */
$select = static function (string $name, array $options, string $selected, string $group): string {
    $html = '<select name="rows[__I__][' . e($name) . ']" data-batch-field="' . e($name) . '">';

    foreach ($options as $value) {
        $html .= '<option value="' . e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . e(code_label($group, $value)) . '</option>';
    }

    return $html . '</select>';
};
?>
<p class="muted small"><a href="<?= e(url('/keys')) ?>">← <?= e(__('ui.nav.vault')) ?></a></p>
<div class="page-head">
    <h1><?= e(__('ui.batch.title')) ?></h1>
    <?php $helpPage = 'keys'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
</div>
<p class="muted"><?= e(sprintf(__('ui.batch.intro'), KeysController::BATCH_ROWS, KeysController::BATCH_CODES)) ?></p>

<?php if ($vaultLocked): ?>
    <?php $back = '/keys'; require dirname(__DIR__) . '/partials/vault_unlock.php'; ?>
<?php else: ?>
<form class="batch-form" autocomplete="off" data-key-batch data-endpoint="/keys/batch">
    <ol class="batch-rows" data-batch-rows></ol>

    <div class="batch-foot card">
        <button type="button" class="btn" data-batch-add>+ <?= e(__('ui.batch.add_row')) ?></button>
        <span class="muted batch-total" data-batch-total data-template="<?= e(__('ui.batch.total')) ?>"></span>
        <span class="batch-result muted small" data-batch-result></span>
        <button type="submit" class="btn primary"><?= e(__('ui.batch.save')) ?></button>
    </div>

    <template data-batch-template>
        <li class="batch-row card" data-batch-row>
            <div class="batch-row-head">
                <span class="batch-num" data-batch-num></span>
                <label class="batch-game">
                    <span><?= e(__('ui.field.game')) ?></span>
                    <select name="rows[__I__][game_id]" data-picker="games" data-batch-game data-placeholder="<?= e(__('ui.batch.game_placeholder')) ?>">
                        <option value=""></option>
                    </select>
                </label>
                <button type="button" class="batch-remove" data-batch-remove aria-label="<?= e(__('ui.action.remove')) ?>" title="<?= e(__('ui.action.remove')) ?>">×</button>
            </div>
            <div class="batch-row-body">
                <div class="grid batch-fields">
                    <label><span><?= e(__('ui.field.platform')) ?></span><?= $select('game_platform', $platforms, 'pc_steam', 'game_platform') ?></label>
                    <label><span><?= e(__('ui.field.source')) ?></span><?= $select('key_platform', $sources, $sources[0] ?? '', 'key_platform') ?></label>
                    <label><span><?= e(__('ui.field.key_type')) ?></span><?= $select('key_type', ['common', 'review'], 'common', 'key_type') ?></label>
                    <label><span><?= e(__('ui.field.content_type')) ?></span><?= $select('content_type', ['game', 'dlc'], 'game', 'content_type') ?></label>
                    <label><span><?= e(__('ui.field.status')) ?></span><?= $select('status', $statuses, 'available', 'key_status') ?></label>
                    <label>
                        <span><?= e(__('ui.field.redeem_by')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                        <input type="datetime-local" name="rows[__I__][expires_at]" data-batch-field="expires_at">
                    </label>
                    <label>
                        <span><?= e(__('ui.field.region')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                        <input type="text" name="rows[__I__][region]" maxlength="50" data-batch-field="region">
                    </label>
                </div>
                <label class="batch-codes">
                    <span><?= e(__('ui.field.key_codes')) ?> <small class="muted" data-batch-count data-template="<?= e(__('ui.batch.codes_count')) ?>"></small></span>
                    <textarea name="rows[__I__][codes]" rows="4" class="key-area masked" spellcheck="false" placeholder="<?= e(__('ui.label.one_per_line')) ?>"></textarea>
                </label>
                <label>
                    <span><?= e(__('ui.field.notes')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                    <input type="text" name="rows[__I__][notes]" data-batch-field="notes">
                </label>
                <p class="batch-error" data-batch-error hidden></p>
            </div>
        </li>
    </template>
</form>
<?php endif; ?>
