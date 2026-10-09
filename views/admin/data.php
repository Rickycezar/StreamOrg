<?php
/**
 * Administration → User data: tabs for the four lists (with how many items
 * and accounts each has), the filters, the chosen list (views/admin/data/)
 * and its pages.
 *
 * @var string $subject @var array $filters @var int $page @var int $pages @var int $total
 * @var list<array> $rows @var list<array> $users @var array $totals
 */
$link = static function (array $change) use ($subject, $filters): string {
    $query = array_merge(['user' => $filters['user'], 'status' => $filters['status'], 'q' => $filters['q']], $change);

    return url('/admin/data/' . $subject . '?' . http_build_query(array_filter($query, static fn ($v): bool => $v !== null && $v !== '' && $v !== 1)));
};
?>
<h1><?= e(__('ui.admin_data.title')) ?></h1>
<p class="muted"><?= e(__('ui.admin_data.intro')) ?></p>

<nav class="bot-tabs data-tabs" aria-label="<?= e(__('ui.admin_data.title')) ?>">
    <?php foreach (AdminData::SUBJECTS as $s): ?>
        <a href="<?= e(url('/admin/data/' . $s . ($filters['user'] ? '?user=' . (int) $filters['user'] : ''))) ?>" class="<?= $s === $subject ? 'active' : '' ?>" <?= $s === $subject ? 'aria-current="page"' : '' ?>>
            <?= e(__('ui.admin_data.tab_' . $s)) ?> <small class="muted"><?= (int) $totals[$s]['items'] ?></small>
        </a>
    <?php endforeach; ?>
</nav>

<form class="card filters" method="get" action="<?= e(url('/admin/data/' . $subject)) ?>">
    <label>
        <span><?= e(__('ui.admin_data.user')) ?></span>
        <select name="user">
            <option value=""><?= e(__('ui.admin_data.all_users')) ?></option>
            <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filters['user'] === $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?> (<?= $u['items'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if (AdminData::STATUSES[$subject] !== []): ?>
        <label>
            <span><?= e(__('ui.field.status')) ?></span>
            <select name="status">
                <option value=""><?= e(__('ui.admin_data.all_statuses')) ?></option>
                <?php foreach (AdminData::STATUSES[$subject] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(code_label(['content' => 'stream_status', 'keys' => 'key_status', 'collabs' => 'collab_status'][$subject], $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <label>
        <span><?= e(__('ui.action.search')) ?></span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(__('ui.admin_data.search_' . $subject)) ?>">
    </label>
    <div class="filter-actions">
        <button type="submit" class="btn"><?= e(__('ui.action.filter')) ?></button>
        <a class="btn ghost" href="<?= e(url('/admin/data/' . $subject)) ?>"><?= e(__('ui.action.clear')) ?></a>
    </div>
</form>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.admin_data.tab_' . $subject)) ?> (<?= $total ?>)</h2>
    </div>
    <?php if ($subject === 'keys'): ?>
        <p class="muted small"><?= e(__('ui.admin_data.keys_note')) ?></p>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <?= View::partial('admin/data/' . $subject, ['rows' => $rows, 'userLink' => static fn (array $r): string => url('/admin/data/' . $subject . '?user=' . (int) $r['user_id'])]) ?>
        </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="pager" aria-label="<?= e(__('ui.admin_data.pages')) ?>">
            <?php if ($page > 1): ?><a class="btn small" href="<?= e($link(['page' => $page - 1])) ?>">← <?= e(__('ui.admin_data.previous')) ?></a><?php endif; ?>
            <span class="muted small"><?= e(sprintf(__('ui.admin_data.page_of'), $page, $pages)) ?></span>
            <?php if ($page < $pages): ?><a class="btn small" href="<?= e($link(['page' => $page + 1])) ?>"><?= e(__('ui.admin_data.next')) ?> →</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
