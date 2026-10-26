<?php
/** @var array $streamers @var array $filters @var array $platforms @var bool $twitchReady
 *  @var array<int, array{user_id:int, name:string, login:string}> $linked streamers who use StreamOrg (plan collabs together) */
?>
<h1><?= e(__('ui.nav.streamers')) ?></h1>
<p class="muted"><?= e(__('ui.message.streamers_intro')) ?></p>

<?php $tab = ($_GET['tab'] ?? '') === 'add' ? 'add' : 'streamers'; ?>
<?= View::partial('partials/tabs', ['name' => 'streamers', 'active' => $tab, 'tabs' => [
    'streamers' => __('ui.nav.streamers'),
    'add' => __('ui.action.import_twitch'),
]]) ?>

<div data-tab-panel="add" <?= $tab === 'add' ? '' : 'hidden' ?>>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.action.import_twitch')) ?></h2>
        <?php if (!$twitchReady): ?>
            <span class="badge warn"><?= e(__('ui.label.needs_credentials')) ?></span>
        <?php endif; ?>
    </div>

    <?php if (!$twitchReady): ?>
        <p class="empty"><?= e(__('ui.message.twitch_unconfigured')) ?></p>
    <?php else: ?>
        <label>
            <span><?= e(__('ui.action.search')) ?></span>
            <input type="search" id="twitch-search" autocomplete="off"
                   placeholder="<?= e(__('ui.label.search_hint')) ?>">
        </label>
        <div id="twitch-results" class="results"></div>
    <?php endif; ?>
</section>
</div>

<div data-tab-panel="streamers" <?= $tab === 'streamers' ? '' : 'hidden' ?>>
<form class="card filters" method="get" action="<?= e(url('/streamers')) ?>" data-filters>
    <?= View::partial('partials/filter_search', ['value' => $filters['q']]) ?>
    <?= View::partial('partials/filter_actions', ['clear' => '/streamers', 'active' => $filters['q'] !== '']) ?>
</form>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.streamers')) ?> (<?= count($streamers) ?>)</h2>
        <button type="button" class="btn primary" data-new data-modal-form="#add-streamer"
                data-modal-title="<?= e(__('ui.action.create')) ?>"><?= e(__('ui.action.create')) ?></button>
    </div>

    <form id="add-streamer" method="post" action="<?= e(url('/streamers')) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <div class="grid">
            <label class="grow">
                <span><?= e(__('ui.field.name')) ?></span>
                <input type="text" name="name" required>
            </label>
            <label>
                <span><?= e(__('ui.field.platform')) ?></span>
                <select name="platform">
                    <?php foreach ($platforms as $platform): ?>
                        <option value="<?= e($platform) ?>" <?= $platform === 'twitch' ? 'selected' : '' ?>>
                            <?= e(code_label('streaming_platform', $platform)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.handle')) ?></span>
                <input type="text" name="handle" autocomplete="off">
            </label>
            <label>
                <span><?= e(__('ui.field.email')) ?></span>
                <input type="email" name="email">
            </label>
        </div>
        <label class="inline">
            <input type="checkbox" name="is_favorite" value="1">
            <span><?= e(__('ui.label.favorite')) ?></span>
        </label>
        <label>
            <span><?= e(__('ui.field.notes')) ?></span>
            <input type="text" name="notes">
        </label>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>

    <?php if ($streamers === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table data-sortable data-table="streamers">
            <thead>
            <tr>
                <th class="col-actions"><span class="visually-hidden"><?= e(__('ui.label.actions')) ?></span></th>
                <th data-col="name"><?= e(__('ui.field.name')) ?></th>
                <th data-col="channels"><?= e(__('ui.field.channels')) ?></th>
                <th data-col="type"><?= e(__('ui.field.broadcaster_type')) ?></th>
                <th data-col="collabs"><?= e(__('ui.nav.collabs')) ?></th>
                <th data-col="streams"><?= e(__('ui.nav.content')) ?></th>
                <th data-col="source"><?= e(__('ui.field.source')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($streamers as $row): ?>
                <tr>
                    <td class="rowactions">
                        <?php if ($row['source_ref']): ?>
                            <button type="button" class="btn small streamer-refresh" data-ref="<?= e($row['source_ref']) ?>"
                                    title="<?= e(__('ui.action.refresh_hint')) ?>"><?= icon_label('refresh', __('ui.action.refresh')) ?></button>
                        <?php endif; ?>
                        <button type="button" class="btn small" data-modal-form="#edit-streamer-<?= (int) $row['id'] ?>"
                                data-modal-title="<?= e($row['name']) ?>"><?= icon_label('edit', __('ui.action.edit')) ?></button>
                    </td>
                    <td data-card-title data-col="name" data-label="<?= e(__('ui.field.name')) ?>">
                        <span class="streamer-name">
                            <?php if ($row['avatar_url']): ?>
                                <img class="avatar" src="<?= e($row['avatar_url']) ?>" alt="" loading="lazy" width="26" height="26">
                            <?php endif; ?>
                            <span>
                                <?= e($row['name']) ?>
                                <?php if (isset($linked[(int) $row['id']])): ?>
                                    <span class="badge on-streamorg" title="<?= e(__('ui.label.on_streamorg_hint')) ?>"><?= e(__('ui.label.on_streamorg')) ?></span>
                                <?php endif; ?>
                                <?php if ($row['is_favorite']): ?>
                                    <span class="badge ok"><?= e(__('ui.label.favorite')) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($row['notes'])): ?>
                                    <small class="muted block"><?= e($row['notes']) ?></small>
                                <?php endif; ?>
                            </span>
                        </span>
                    </td>
                    <td data-col="channels" data-label="<?= e(__('ui.field.channels')) ?>"><small><?= e($row['channels'] ?: '—') ?></small></td>
                    <td data-col="type" data-label="<?= e(__('ui.field.broadcaster_type')) ?>">
                        <?= $row['broadcaster_type']
                            ? '<span class="badge">' . e($row['broadcaster_type']) . '</span>'
                            : '<span class="muted">—</span>' ?>
                    </td>
                    <td data-col="collabs" data-label="<?= e(__('ui.nav.collabs')) ?>"><?= (int) $row['collab_count'] ?></td>
                    <td data-col="streams" data-label="<?= e(__('ui.nav.content')) ?>"><?= (int) $row['stream_count'] ?></td>
                    <td data-col="source" data-label="<?= e(__('ui.field.source')) ?>">
                        <?php if ($row['source_provider']): ?>
                            <?= e(code_label('streaming_platform', $row['source_provider'])) ?>
                            <?php if ($row['source_synced_at']): ?>
                                <small class="muted block"><?= e(fmt_date($row['source_synced_at'])) ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted"><?= e(__('ui.label.manual_entry')) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr id="edit-streamer-<?= (int) $row['id'] ?>" class="editrow hidden">
                    <td colspan="7">
                        <form class="inline-edit" data-endpoint="/streamers/update" data-id="<?= (int) $row['id'] ?>">
                            <div class="grid">
                                <label class="grow">
                                    <span><?= e(__('ui.field.name')) ?></span>
                                    <input type="text" name="name" value="<?= e($row['name']) ?>" required>
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.platform')) ?></span>
                                    <select name="platform">
                                        <option value=""></option>
                                        <?php foreach ($platforms as $platform): ?>
                                            <option value="<?= e($platform) ?>"><?= e(code_label('streaming_platform', $platform)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.handle')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                                    <input type="text" name="handle" autocomplete="off">
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.email')) ?></span>
                                    <input type="email" name="email" value="<?= e($row['email'] ?? '') ?>">
                                </label>
                            </div>
                            <label class="inline">
                                <input type="checkbox" name="is_favorite" value="1" <?= $row['is_favorite'] ? 'checked' : '' ?>>
                                <span><?= e(__('ui.label.favorite')) ?></span>
                            </label>
                            <label>
                                <span><?= e(__('ui.field.notes')) ?></span>
                                <input type="text" name="notes" value="<?= e($row['notes'] ?? '') ?>">
                            </label>
                            <div class="editrow-actions">
                                <button type="submit" class="btn primary small"><?= e(__('ui.action.save')) ?></button>
                                <button type="button" class="btn small row-cancel"><?= e(__('ui.action.cancel')) ?></button>
                                <span class="edit-result muted small"></span>
                                <button type="button" class="btn small danger-btn row-delete"
                                        data-endpoint="/streamers/delete" data-id="<?= (int) $row['id'] ?>"
                                        data-label="<?= e($row['name']) ?>"><?= e(__('ui.action.delete')) ?></button>
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
</div>
