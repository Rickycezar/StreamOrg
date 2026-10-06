<?php
/** @var array $embargoes @var array $uncovered @var array $kinds
 *  @var array $games @var array $platforms */
$active = array_filter($embargoes, static fn (array $e): bool => (bool) $e['active']);
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.embargoes')) ?></h1>
    <?php $helpPage = 'embargoes'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
</div>
<p class="muted"><?= e(__('ui.message.embargoes_intro')) ?></p>

<section class="tiles">
    <span class="tile">
        <span class="tile-value <?= count($active) > 0 ? 'danger' : '' ?>"><?= count($active) ?></span>
        <span class="tile-label"><?= e(__('ui.label.active_embargoes')) ?></span>
    </span>
    <span class="tile total">
        <span class="tile-value"><?= count($embargoes) ?></span>
        <span class="tile-label"><?= e(__('ui.label.total_embargoes')) ?></span>
    </span>
    <span class="tile" title="<?= e(__('ui.label.uncovered_hint')) ?>">
        <span class="tile-value <?= count($uncovered) > 0 ? 'warn' : '' ?>"><?= count($uncovered) ?></span>
        <span class="tile-label"><?= e(__('ui.label.uncovered')) ?></span>
    </span>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.embargoes')) ?> (<?= count($embargoes) ?>)</h2>
        <button type="button" class="btn primary" data-new data-modal-form="#add-embargo"
                data-modal-title="<?= e(__('ui.action.create')) ?>"><?= e(__('ui.action.create')) ?></button>
    </div>

<form id="add-embargo" method="post" action="<?= e(url('/embargoes')) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <div class="grid">
            <label class="grow">
                <span><?= e(__('ui.field.game')) ?></span>
                <select name="game_id" required data-picker="games">
                    <option value=""></option>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.kind')) ?></span>
                <select name="kind">
                    <?php foreach ($kinds as $kind): ?>
                        <option value="<?= e($kind) ?>"><?= e(code_label('embargo_kind', $kind)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.platform')) ?></span>
                <select name="game_platform">
                    <option value=""><?= e(__('ui.label.all_platforms')) ?></option>
                    <?php foreach ($platforms as $platform): ?>
                        <option value="<?= e($platform) ?>"><?= e(code_label('game_platform', $platform)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.lifts_at')) ?></span>
                <input type="datetime-local" name="lifts_at" required>
            </label>
            <label class="grow">
                <span><?= e(__('ui.field.label')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                <input type="text" name="label" placeholder="<?= e(__('ui.label.embargo_label_hint')) ?>">
            </label>
        </div>
        <label>
            <span><?= e(__('ui.field.notes')) ?></span>
            <input type="text" name="note">
        </label>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>

    <?php if ($embargoes === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th><?= e(__('ui.field.game')) ?></th>
                <th><?= e(__('ui.field.kind')) ?></th>
                <th><?= e(__('ui.field.platform')) ?></th>
                <th><?= e(__('ui.field.lifts_at')) ?></th>
                <th><?= e(__('ui.field.source')) ?></th>
                <th><?= e(__('ui.nav.keys')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($embargoes as $row): ?>
                <tr>
                    <td>
                        <?= e($row['game_title']) ?>
                        <?php if ($row['label']): ?>
                            <small class="muted block"><?= e($row['label']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e(code_label('embargo_kind', $row['kind'])) ?></td>
                    <td>
                        <?php if ($row['platform_code']): ?>
                            <?= e(code_label('game_platform', $row['platform_code'])) ?>
                        <?php else: ?>
                            <span class="muted"><?= e(__('ui.label.all_platforms')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e(fmt_datetime($row['lifts_at'])) ?>
                        <?php if ($row['active']): ?>
                            <span class="badge danger"><?= e(__('ui.label.active')) ?></span>
                        <?php else: ?>
                            <span class="badge ok"><?= e(__('ui.label.lifted')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><small class="muted"><?= e(code_label('embargo_source', $row['source'])) ?></small></td>
                    <td><?= (int) $row['key_count'] ?></td>
                    <td class="rowactions">
                        <button type="button" class="btn small" data-modal-form="#edit-emb-<?= (int) $row['id'] ?>"
                                data-modal-title="<?= e($row['game_title']) ?>">
                            <?= e(__('ui.action.edit')) ?>
                        </button>
                    </td>
                </tr>
                <tr id="edit-emb-<?= (int) $row['id'] ?>" class="editrow hidden">
                    <td colspan="7">
                        <form class="inline-edit" data-endpoint="/embargoes/update" data-id="<?= (int) $row['id'] ?>">
                            <div class="grid">
                                <label>
                                    <span><?= e(__('ui.field.lifts_at')) ?></span>
                                    <input type="datetime-local" name="lifts_at"
                                           value="<?= e(substr(str_replace(' ', 'T', $row['lifts_at']), 0, 16)) ?>" required>
                                </label>
                                <label class="grow">
                                    <span><?= e(__('ui.field.label')) ?></span>
                                    <input type="text" name="label" value="<?= e($row['label'] ?? '') ?>">
                                </label>
                                <label class="grow">
                                    <span><?= e(__('ui.field.notes')) ?></span>
                                    <input type="text" name="note" value="<?= e($row['note'] ?? '') ?>">
                                </label>
                            </div>
                            <div class="editrow-actions">
                                <button type="submit" class="btn primary small"><?= e(__('ui.action.save')) ?></button>
                                <button type="button" class="btn small row-cancel"><?= e(__('ui.action.cancel')) ?></button>
                                <span class="edit-result muted small"></span>
                                <button type="button" class="btn small danger-btn row-delete"
                                        data-endpoint="/embargoes/delete" data-id="<?= (int) $row['id'] ?>"
                                        data-label="<?= e($row['game_title']) ?>"><?= e(__('ui.action.delete')) ?></button>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($uncovered !== []): ?>
<section class="card">
    <h2><?= e(__('ui.label.uncovered')) ?> (<?= count($uncovered) ?>)</h2>
    <p class="muted small"><?= e(__('ui.label.uncovered_hint')) ?></p>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th><?= e(__('ui.field.game')) ?></th>
            <th><?= e(__('ui.field.release_date')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($uncovered as $row): ?>
            <tr>
                <td><?= e($row['title']) ?></td>
                <td>
                    <?php if ($row['release_date'] && $row['release_precision'] === 'day'): ?>
                        <?= e(fmt_date($row['release_date'])) ?>
                    <?php elseif ($row['release_date']): ?>
                        <span class="badge warn"><?= e(code_label('release_precision', $row['release_precision'])) ?></span>
                        <?= e(substr((string) $row['release_date'], 0, 4)) ?>
                    <?php else: ?>
                        <span class="badge warn"><?= e(__('ui.label.no_release_date')) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>
