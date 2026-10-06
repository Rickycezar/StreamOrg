<?php
/** @var array $collabs @var array $counts @var array $cast @var array $filters
 *  @var array $statuses @var array $roles @var array $streamers @var array $platforms */
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.collabs')) ?></h1>
    <?php $helpPage = 'collabs'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
</div>
<p class="muted"><?= e(__('ui.message.collabs_intro')) ?></p>

<section class="tiles">
    <?php foreach ($statuses as $status): ?>
        <a class="tile <?= $filters['status'] === $status ? 'active' : '' ?>"
           href="<?= e(url('/collabs?status=' . $status)) ?>">
            <span class="tile-value"><?= (int) ($counts[$status] ?? 0) ?></span>
            <span class="tile-label"><?= e(code_label('collab_status', $status)) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.collabs')) ?> (<?= count($collabs) ?>)</h2>
        <button type="button" class="btn primary" data-new data-modal-form="#add-collab"
                data-modal-title="<?= e(__('ui.action.create')) ?>"><?= e(__('ui.action.create')) ?></button>
    </div>

    <?php if ($streamers === []): ?>
        <p class="empty">
            <?= e(__('ui.message.no_streamers_yet')) ?>
            <a href="<?= e(url('/streamers')) ?>"><?= e(__('ui.nav.streamers')) ?></a>
        </p>
    <?php endif; ?>

    <form id="add-collab" method="post" action="<?= e(url('/collabs')) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <div class="grid">
            <label class="grow">
                <span><?= e(__('ui.field.title')) ?></span>
                <input type="text" name="title" required>
            </label>
            <label>
                <span><?= e(__('ui.field.status')) ?></span>
                <select name="status">
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>"><?= e(code_label('collab_status', $status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.platform')) ?></span>
                <select name="platform">
                    <option value=""></option>
                    <?php foreach ($platforms as $platform): ?>
                        <option value="<?= e($platform) ?>" <?= $platform === 'twitch' ? 'selected' : '' ?>>
                            <?= e(code_label('streaming_platform', $platform)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.proposed_at')) ?></span>
                <input type="datetime-local" name="proposed_at">
            </label>
        </div>

        <fieldset class="inset">
            <legend><?= e(__('ui.field.streamers')) ?></legend>
            <p class="muted small"><?= e(__('ui.message.collab_cast_hint')) ?></p>
            <div class="cast-list">
                <?php foreach ($streamers as $s): ?>
                    <label class="cast-row">
                        <input type="checkbox" name="streamers[]" value="<?= (int) $s['id'] ?>">
                        <span class="cast-name"><?= e($s['name']) ?></span>
                        <select name="roles[<?= (int) $s['id'] ?>]" class="cast-role">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= e($role) ?>" <?= $role === 'guest' ? 'selected' : '' ?>>
                                    <?= e(code_label('collab_role', $role)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <label>
            <span><?= e(__('ui.field.notes')) ?></span>
            <textarea name="notes" rows="2"></textarea>
        </label>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>

    <?php if ($collabs === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table data-table="collabs">
            <thead>
            <tr>
                <th data-col="title"><?= e(__('ui.field.title')) ?></th>
                <th data-col="streamers"><?= e(__('ui.field.streamers')) ?></th>
                <th data-col="platform"><?= e(__('ui.field.platform')) ?></th>
                <th data-col="when"><?= e(__('ui.field.proposed_at')) ?></th>
                <th data-col="status"><?= e(__('ui.field.status')) ?></th>
                <th data-col="streams"><?= e(__('ui.nav.content')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($collabs as $row): $picked = $cast[(int) $row['id']] ?? []; ?>
                <tr>
                    <td data-col="title">
                        <?= e($row['title']) ?>
                        <?php if (!empty($row['notes'])): ?>
                            <small class="muted block"><?= e($row['notes']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td data-col="streamers">
                        <?php if ($row['streamers']): ?>
                            <?= e($row['streamers']) ?>
                        <?php else: ?>
                            <span class="badge warn"><?= e(__('ui.label.no_streamers')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-col="platform"><?= e($row['platform_code'] ? code_label('streaming_platform', $row['platform_code']) : '—') ?></td>
                    <td data-col="when"><?= e($row['proposed_at'] ? fmt_datetime($row['proposed_at']) : '—') ?></td>
                    <td data-col="status"><span class="badge"><?= e(code_label('collab_status', $row['status'])) ?></span></td>
                    <td data-col="streams">
                        <?php if ((int) $row['stream_count'] > 0): ?>
                            <a href="<?= e(url('/content')) ?>"><?= (int) $row['stream_count'] ?></a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="rowactions">
                        <button type="button" class="btn small primary collab-plan" data-id="<?= (int) $row['id'] ?>"
                                title="<?= e(__('ui.action.plan_stream_hint')) ?>">
                            <?= e(__('ui.action.plan_stream')) ?>
                        </button>
                        <button type="button" class="btn small" data-modal-form="#edit-collab-<?= (int) $row['id'] ?>"
                                data-modal-title="<?= e($row['title']) ?>"><?= e(__('ui.action.edit')) ?></button>
                    </td>
                </tr>
                <tr id="edit-collab-<?= (int) $row['id'] ?>" class="editrow hidden">
                    <td colspan="7">
                        <form class="inline-edit" data-endpoint="/collabs/update" data-id="<?= (int) $row['id'] ?>">
                            <div class="grid">
                                <label class="grow">
                                    <span><?= e(__('ui.field.title')) ?></span>
                                    <input type="text" name="title" value="<?= e($row['title']) ?>" required>
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.status')) ?></span>
                                    <select name="status">
                                        <?php foreach ($statuses as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>>
                                                <?= e(code_label('collab_status', $status)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.platform')) ?></span>
                                    <select name="platform">
                                        <option value=""></option>
                                        <?php foreach ($platforms as $platform): ?>
                                            <option value="<?= e($platform) ?>" <?= $row['platform_code'] === $platform ? 'selected' : '' ?>>
                                                <?= e(code_label('streaming_platform', $platform)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.proposed_at')) ?></span>
                                    <input type="datetime-local" name="proposed_at"
                                           value="<?= e($row['proposed_at'] ? substr(str_replace(' ', 'T', $row['proposed_at']), 0, 16) : '') ?>">
                                </label>
                            </div>

                            <fieldset class="inset">
                                <legend><?= e(__('ui.field.streamers')) ?></legend>
                                <div class="cast-list">
                                    <?php foreach ($streamers as $s): ?>
                                        <label class="cast-row">
                                            <input type="checkbox" name="streamers[]" value="<?= (int) $s['id'] ?>"
                                                   <?= in_array((int) $s['id'], $picked, true) ? 'checked' : '' ?>>
                                            <span class="cast-name"><?= e($s['name']) ?></span>
                                            <select name="roles[<?= (int) $s['id'] ?>]" class="cast-role">
                                                <?php foreach ($roles as $role): ?>
                                                    <option value="<?= e($role) ?>" <?= $role === 'guest' ? 'selected' : '' ?>>
                                                        <?= e(code_label('collab_role', $role)) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <label>
                                <span><?= e(__('ui.field.notes')) ?></span>
                                <input type="text" name="notes" value="<?= e($row['notes'] ?? '') ?>">
                            </label>
                            <div class="editrow-actions">
                                <button type="submit" class="btn primary small"><?= e(__('ui.action.save')) ?></button>
                                <button type="button" class="btn small row-cancel"><?= e(__('ui.action.cancel')) ?></button>
                                <span class="edit-result muted small"></span>
                                <button type="button" class="btn small danger-btn row-delete"
                                        data-endpoint="/collabs/delete" data-id="<?= (int) $row['id'] ?>"
                                        data-label="<?= e($row['title']) ?>"><?= e(__('ui.action.delete')) ?></button>
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
