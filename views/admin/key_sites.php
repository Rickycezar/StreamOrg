<?php /** @var array $rows */ ?>
<h1><?= e(__('ui.nav.key_sites')) ?></h1>
<p class="muted"><?= e(__('ui.message.key_sites_intro')) ?></p>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.key_sites')) ?> (<?= count($rows) ?>)</h2>
        <button type="button" class="btn primary" data-modal-form="#add-key-site"
                data-modal-title="<?= e(__('ui.action.create')) ?>"><?= e(__('ui.action.create')) ?></button>
    </div>

<form id="add-key-site" method="post" action="<?= e(url('/admin/key-sites')) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.code')) ?></span>
                <input type="text" name="code" required pattern="[a-z0-9_]{2,40}" placeholder="daredrop">
                <small class="muted"><?= e(__('ui.label.code_hint')) ?></small>
            </label>
            <label>
                <span><?= e(__('ui.field.website')) ?></span>
                <input type="url" name="website" placeholder="https://">
            </label>
            <label>
                <span><?= e(__('ui.field.sort_order')) ?></span>
                <input type="number" name="sort_order" value="0">
            </label>
        </div>
        <label class="inline">
            <input type="checkbox" name="tags_content" value="1" checked>
            <span><?= e(__('ui.field.tags_content')) ?>
                  <small class="muted"><?= e(__('ui.label.tags_content_hint')) ?></small></span>
        </label>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th><?= e(__('ui.field.label')) ?></th>
            <th><?= e(__('ui.field.code')) ?></th>
            <th><?= e(__('ui.field.website')) ?></th>
            <th><?= e(__('ui.field.tags_content')) ?></th>
            <th><?= e(__('ui.nav.keys')) ?></th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
            $label   = code_label('key_platform', $row['code']);
            $missing = $label === 'key_platform.' . $row['code'];
        ?>
            <tr>
                <td>
                    <?= e($missing ? $row['code'] : $label) ?>
                    <?php if ($missing): ?>
                        <span class="badge warn" title="<?= e(sprintf(__('ui.message.code_added_needs_label'), 'key_platform.' . $row['code'])) ?>">
                            <?= e(__('ui.label.no_label')) ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td><code><?= e($row['code']) ?></code></td>
                <td><?= safe_url($row['website']) ? '<a href="' . e(safe_url($row['website'])) . '" target="_blank" rel="noopener noreferrer">' . e($row['website']) . '</a>' : e($row['website'] ?: '—') ?></td>
                <td>
                    <?php if ($row['tags_content']): ?>
                        <span class="badge ok"><?= e(__('ui.label.yes')) ?></span>
                    <?php else: ?>
                        <span class="badge"><?= e(__('ui.label.no')) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= (int) $row['key_count'] ?></td>
                <td class="rowactions">
                    <button type="button" class="btn small" data-modal-form="#edit-site-<?= (int) $row['id'] ?>"
                            data-modal-title="<?= e($row['code']) ?>"><?= e(__('ui.action.edit')) ?></button>
                    <button type="button" class="btn small danger-btn row-delete"
                            data-endpoint="/admin/catalogue/delete" data-kind="key_site"
                            data-id="<?= (int) $row['id'] ?>" data-label="<?= e($row['code']) ?>">
                        <?= e(__('ui.action.delete')) ?>
                    </button>
                </td>
            </tr>
            <tr id="edit-site-<?= (int) $row['id'] ?>" class="editrow hidden">
                <td colspan="6">
                    <form class="inline-edit" data-endpoint="/admin/key-sites/update" data-id="<?= (int) $row['id'] ?>">
                        <div class="grid">
                            <label class="grow">
                                <span><?= e(__('ui.field.website')) ?></span>
                                <input type="url" name="website" value="<?= e($row['website'] ?? '') ?>">
                            </label>
                            <label>
                                <span><?= e(__('ui.field.sort_order')) ?></span>
                                <input type="number" name="sort_order" value="<?= (int) $row['sort_order'] ?>">
                            </label>
                        </div>
                        <label class="inline">
                            <input type="checkbox" name="tags_content" value="1" <?= $row['tags_content'] ? 'checked' : '' ?>>
                            <span><?= e(__('ui.field.tags_content')) ?>
                                  <small class="muted"><?= e(__('ui.label.tags_content_hint')) ?></small></span>
                        </label>
                        <div class="editrow-actions">
                            <button type="submit" class="btn primary small"><?= e(__('ui.action.save')) ?></button>
                            <button type="button" class="btn small row-cancel"><?= e(__('ui.action.cancel')) ?></button>
                            <span class="edit-result muted small"></span>
                        </div>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
