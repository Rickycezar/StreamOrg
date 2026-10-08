<?php
/**
 * Administration → Bug tracker: reports by status (tiles), filtered by
 * priority, impact or a search, most urgent first, with a dot on the ones
 * that changed since an administrator last opened them.
 *
 * @var list<array> $bugs @var array<string,int> $counts @var string $show @var string $priority @var string $impact @var string $query
 */
$tiles = ['open', 'new', 'confirmed', 'in_progress', 'need_info', 'fixed', 'closed', 'wont_fix', 'all'];
$open  = array_sum(array_map(static fn (string $s): int => $counts[$s] ?? 0, BugReports::OPEN));
$total = array_sum(array_map(static fn (string $s): int => $counts[$s] ?? 0, BugReports::STATUSES));
$link  = static fn (array $change): string => url('/admin/bugs?' . http_build_query(array_filter(array_merge(['show' => $show, 'priority' => $priority, 'impact' => $impact, 'q' => $query], $change))));
?>
<h1><?= e(__('ui.nav.bug_tracker')) ?></h1>
<p class="muted"><?= e(__('ui.support.tracker_intro')) ?></p>

<section class="tiles compact bug-tiles">
    <?php foreach ($tiles as $tile):
        $n = $tile === 'open' ? $open : ($tile === 'all' ? $total : ($counts[$tile] ?? 0)); ?>
        <a class="tile bug-tile-<?= e($tile) ?><?= $show === $tile ? ' active' : '' ?>" href="<?= e($link(['show' => $tile])) ?>">
            <span class="tile-value"><?= (int) $n ?></span>
            <span class="tile-label"><?= e($tile === 'open' ? __('ui.support.open_bugs') : ($tile === 'all' ? __('ui.support.all_bugs') : __('ui.bug_status.' . $tile))) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<form class="card filters" method="get" action="<?= e(url('/admin/bugs')) ?>">
    <input type="hidden" name="show" value="<?= e($show) ?>">
    <label>
        <span><?= e(__('ui.action.search')) ?></span>
        <input type="search" name="q" value="<?= e($query) ?>" placeholder="<?= e(__('ui.support.tracker_search_hint')) ?>">
    </label>
    <label>
        <span><?= e(__('ui.support.f_priority')) ?></span>
        <select name="priority">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <?php foreach (BugReports::PRIORITIES as $p): ?>
                <option value="<?= e($p) ?>" <?= $priority === $p ? 'selected' : '' ?>><?= e(__('ui.bug_priority.' . $p)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span><?= e(__('ui.support.f_impact')) ?></span>
        <select name="impact">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <?php foreach (BugReports::IMPACTS as $i): ?>
                <option value="<?= e($i) ?>" <?= $impact === $i ? 'selected' : '' ?>><?= e(__('ui.bug_impact.' . $i)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="filter-actions">
        <button type="submit" class="btn"><?= e(__('ui.action.filter')) ?></button>
        <a class="btn ghost" href="<?= e(url('/admin/bugs')) ?>"><?= e(__('ui.action.clear')) ?></a>
    </div>
</form>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.support.reports')) ?> (<?= count($bugs) ?>)</h2>
        <?php if ($counts['unread'] > 0): ?><span class="badge warn"><?= e(sprintf(__('ui.support.n_with_news'), $counts['unread'])) ?></span><?php endif; ?>
    </div>
    <?php if ($bugs === []): ?>
        <p class="empty"><?= e(__('ui.support.tracker_empty')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="bug-table" data-sortable>
                <thead>
                <tr>
                    <th class="col-actions"><span class="visually-hidden"><?= e(__('ui.label.actions')) ?></span></th>
                    <th>#</th>
                    <th><?= e(__('ui.support.f_title')) ?></th>
                    <th><?= e(__('ui.support.f_priority')) ?></th>
                    <th><?= e(__('ui.support.f_impact')) ?></th>
                    <th><?= e(__('ui.support.f_status')) ?></th>
                    <th><?= e(__('ui.support.f_reporter')) ?></th>
                    <th><?= e(__('ui.support.f_updated')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($bugs as $b): ?>
                    <tr class="<?= $b['unread'] ? 'is-new' : '' ?>">
                        <td class="rowactions"><a class="btn small" href="<?= e(url('/admin/bugs/view?id=' . (int) $b['id'])) ?>"><?= e(__('ui.support.open_report')) ?></a></td>
                        <td data-sort="<?= (int) $b['id'] ?>" class="muted">#<?= (int) $b['id'] ?></td>
                        <td>
                            <?php if ($b['unread']): ?><span class="support-dot" title="<?= e(__('ui.support.news')) ?>"></span><?php endif; ?>
                            <a href="<?= e(url('/admin/bugs/view?id=' . (int) $b['id'])) ?>" class="bug-table-title"><?= e($b['title']) ?></a>
                            <small class="muted block">
                                <?= $b['page'] ? e($b['page']) : '' ?>
                                <?php if ((int) $b['images'] > 0): ?> · 🖼 <?= (int) $b['images'] ?><?php endif; ?>
                                <?php if ((int) $b['comments'] > 0): ?> · 💬 <?= (int) $b['comments'] ?><?php endif; ?>
                            </small>
                        </td>
                        <td data-sort="<?= array_search($b['priority'], array_reverse(BugReports::PRIORITIES), true) ?>"><span class="badge prio-<?= e($b['priority']) ?>"><?= e(__('ui.bug_priority.' . $b['priority'])) ?></span></td>
                        <td data-sort="<?= array_search($b['impact'], BugReports::IMPACTS, true) ?>"><span class="badge impact-<?= e($b['impact']) ?>"><?= e(__('ui.bug_impact.' . $b['impact'])) ?></span></td>
                        <td><span class="badge bug-<?= e($b['status']) ?>"><?= e(__('ui.bug_status.' . $b['status'])) ?></span></td>
                        <td><?= e((string) $b['reporter']) ?></td>
                        <td data-sort="<?= e(sort_key($b['updated_at'])) ?>" class="nowrap"><?= e(fmt_datetime($b['updated_at'], 'd/m H:i')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
