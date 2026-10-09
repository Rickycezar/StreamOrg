<?php
/** Planned content of every account, read-only. @var list<array> $rows @var callable $userLink */
?>
<table data-sortable data-table="admin_data_content">
    <thead>
    <tr>
        <th data-col="user"><?= e(__('ui.admin_data.user')) ?></th>
        <th data-col="title"><?= e(__('ui.field.title')) ?></th>
        <th data-col="games"><?= e(__('ui.nav.games')) ?></th>
        <th data-col="status"><?= e(__('ui.field.status')) ?></th>
        <th data-col="scheduled"><?= e(__('ui.admin_data.scheduled')) ?></th>
        <th data-col="deadline"><?= e(__('ui.admin_data.deadline')) ?></th>
        <th data-col="category"><?= e(__('ui.admin_data.category')) ?></th>
        <th data-col="platform"><?= e(__('ui.admin_data.platform')) ?></th>
        <th data-col="extras"><?= e(__('ui.admin_data.extras')) ?></th>
        <th data-col="created"><?= e(__('ui.admin_data.created')) ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td data-col="user"><a href="<?= e($userLink($r)) ?>"><?= e($r['user_name']) ?></a></td>
            <td data-col="title"><?= e($r['title']) ?></td>
            <td data-col="games"><?= e((string) $r['games']) ?></td>
            <td data-col="status"><span class="badge"><?= e(code_label('stream_status', $r['status'])) ?></span></td>
            <td data-col="scheduled" data-sort="<?= e(sort_key($r['scheduled_start'])) ?>"><?= e($r['scheduled_start'] ? fmt_datetime($r['scheduled_start']) : '—') ?></td>
            <td data-col="deadline" data-sort="<?= e(sort_key($r['deadline'])) ?>"><?= e($r['deadline'] ? fmt_date($r['deadline']) : '—') ?></td>
            <td data-col="category"><?= e((string) $r['category_name']) ?></td>
            <td data-col="platform"><?= e($r['platform'] ? code_label('streaming_platform', $r['platform']) : '') ?></td>
            <td data-col="extras">
                <?php if ($r['is_collab']): ?><span class="badge"><?= e(__('ui.admin_data.collab')) ?></span><?php endif; ?>
                <?php if ((int) $r['keys_linked'] > 0): ?><span class="badge"><?= e(sprintf(__('ui.admin_data.keys_linked'), (int) $r['keys_linked'])) ?></span><?php endif; ?>
                <?php if ($r['twitch_pushed_at']): ?><span class="badge ok"><?= e(__('ui.admin_data.sent_to_twitch')) ?></span><?php endif; ?>
            </td>
            <td data-col="created" data-sort="<?= e(sort_key($r['created_at'])) ?>"><?= e(fmt_date($r['created_at'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
