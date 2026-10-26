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

<form class="card filters" method="get" action="<?= e(url('/admin/data/' . $subject)) ?>" data-filters>
    <?= View::partial('partials/filter_search', ['value' => $filters['q'], 'placeholder' => __('ui.admin_data.search_' . $subject)]) ?>
    <?= View::partial('partials/filter_select', [
        'name' => 'user', 'label' => __('ui.admin_data.user'), 'selected' => (string) ($filters['user'] ?? ''), 'multiple' => false,
        'options' => array_combine(array_map('strval', array_column($users, 'id')), array_map(static fn ($u) => $u['name'] . ' (' . $u['items'] . ')', $users)),
    ]) ?>
    <?php if (AdminData::STATUSES[$subject] !== []): ?>
        <?= View::partial('partials/filter_select', [
            'name' => 'status', 'label' => __('ui.field.status'), 'selected' => $filters['status'], 'multiple' => false,
            'options' => array_combine(AdminData::STATUSES[$subject], array_map(static fn ($s) => code_label(['content' => 'stream_status', 'keys' => 'key_status', 'collabs' => 'collab_status'][$subject], $s), AdminData::STATUSES[$subject])),
        ]) ?>
    <?php endif; ?>
    <?= View::partial('partials/filter_actions', ['clear' => '/admin/data/' . $subject, 'active' => $filters['q'] !== '' || !empty($filters['user']) || $filters['status'] !== '']) ?>
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
