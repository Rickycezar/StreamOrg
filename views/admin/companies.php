<?php /** @var string $kind  @var array $rows */
$isPublisher = $kind === 'publisher';
$action = url($isPublisher ? '/admin/publishers' : '/admin/developers');
$title  = __($isPublisher ? 'ui.nav.publishers' : 'ui.nav.developers');
?>
<h1><?= e($title) ?></h1>

<section class="card">
    <div class="card-head">
        <h2><?= e($title) ?> (<?= count($rows) ?>)</h2>
        <button type="button" class="btn primary" data-modal-form="#add-company"
                data-modal-title="<?= e(__('ui.action.create')) ?>"><?= e(__('ui.action.create')) ?></button>
    </div>

<form id="add-company" method="post" action="<?= e($action) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.name')) ?></span>
                <input type="text" name="name" required>
            </label>
            <label>
                <span><?= e(__('ui.field.website')) ?></span>
                <input type="url" name="website" placeholder="https://">
            </label>
            <label>
                <span><?= e(__('ui.field.country')) ?></span>
                <input type="text" name="country_code" maxlength="2" placeholder="BR">
            </label>
        </div>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>

    <?php if ($rows === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th><?= e(__('ui.field.name')) ?></th>
                <th><?= e(__('ui.field.website')) ?></th>
                <th><?= e(__('ui.field.country')) ?></th>
                <th><?= e(__('ui.nav.games')) ?></th>
                <th><?= e(__('ui.field.source')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['name']) ?></td>
                    <td><?= safe_url($row['website']) ? '<a href="' . e(safe_url($row['website'])) . '" target="_blank" rel="noopener noreferrer">' . e($row['website']) . '</a>' : e($row['website'] ?: '—') ?></td>
                    <td><?= e($row['country_code'] ?: '—') ?></td>
                    <td><?= (int) $row['game_count'] ?></td>
                    <td><?= $row['source_provider'] ? e(code_label('api_provider', $row['source_provider'])) : '—' ?></td>
                    <td class="rowactions">
                        <button type="button" class="btn small danger-btn row-delete"
                                data-endpoint="/admin/catalogue/delete" data-kind="<?= e($kind) ?>"
                                data-id="<?= (int) $row['id'] ?>" data-label="<?= e($row['name']) ?>">
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
