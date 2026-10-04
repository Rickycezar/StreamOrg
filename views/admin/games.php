<?php /** @var array $games @var array $publishers @var array $developers */ ?>
<h1><?= e(__('ui.nav.games')) ?></h1>
<p class="muted">
    <?= e(__('ui.message.games_intro')) ?>
    <a href="<?= e(url('/admin/import')) ?>"><?= e(__('ui.action.import_instead')) ?></a>
</p>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.games')) ?> (<?= count($games) ?>)</h2>
        <button type="button" class="btn primary" data-modal-form="#add-game"
                data-modal-title="<?= e(__('ui.action.create')) ?>"><?= e(__('ui.action.create')) ?></button>
    </div>

<form id="add-game" method="post" action="<?= e(url('/admin/games')) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.title')) ?></span>
                <input type="text" name="title" required>
            </label>
            <label>
                <span><?= e(__('ui.nav.publishers')) ?></span>
                <select name="publisher_id" data-picker="publishers">
                    <option value=""></option>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.nav.developers')) ?></span>
                <select name="developer_id" data-picker="developers">
                    <option value=""></option>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.release_date')) ?></span>
                <input type="date" name="release_date">
            </label>
            <label>
                <span><?= e(__('ui.field.store_url')) ?></span>
                <input type="url" name="store_url" placeholder="https://">
            </label>
        </div>
        <label>
            <span><?= e(__('ui.field.description')) ?></span>
            <textarea name="description" rows="2"></textarea>
        </label>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>

    <?php if ($games === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th><?= e(__('ui.field.title')) ?></th>
                <th><?= e(__('ui.nav.publishers')) ?></th>
                <th><?= e(__('ui.nav.developers')) ?></th>
                <th><?= e(__('ui.field.release_date')) ?></th>
                <th><?= e(__('ui.field.source')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($games as $game): ?>
                <tr>
                    <td>
                        <?php if (safe_url($game['store_url'])): ?>
                            <a href="<?= e(safe_url($game['store_url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($game['title']) ?></a>
                        <?php else: ?>
                            <?= e($game['title']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e($game['publisher_name'] ?: '—') ?></td>
                    <td><?= e($game['developer_name'] ?: '—') ?></td>
                    <td><?= e(fmt_date($game['release_date'])) ?></td>
                    <td><?= $game['source_provider'] ? e(code_label('api_provider', $game['source_provider'])) : '—' ?></td>
                    <td class="rowactions">
                        <button type="button" class="btn small danger-btn row-delete"
                                data-endpoint="/admin/catalogue/delete" data-kind="game"
                                data-id="<?= (int) $game['id'] ?>" data-label="<?= e($game['title']) ?>">
                            <?= e(__('ui.action.delete')) ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
