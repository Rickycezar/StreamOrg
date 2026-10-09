<?php
/** Every account's collabs, with their cast and content. @var list<array> $rows @var callable $userLink */
?>
<table data-sortable data-table="admin_data_collabs">
    <thead>
    <tr>
        <th data-col="user"><?= e(__('ui.admin_data.user')) ?></th>
        <th data-col="title"><?= e(__('ui.field.title')) ?></th>
        <th data-col="cast"><?= e(__('ui.admin_data.cast')) ?></th>
        <th data-col="status"><?= e(__('ui.field.status')) ?></th>
        <th data-col="proposed"><?= e(__('ui.admin_data.proposed_for')) ?></th>
        <th data-col="platform"><?= e(__('ui.admin_data.platform')) ?></th>
        <th data-col="content"><?= e(__('ui.admin_data.content_count')) ?></th>
        <th data-col="created"><?= e(__('ui.admin_data.created')) ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td data-col="user"><a href="<?= e($userLink($r)) ?>"><?= e($r['user_name']) ?></a></td>
            <td data-col="title">
                <?= e($r['title']) ?>
                <?php if ($r['together']): ?><span class="badge ok"><?= e(__('ui.admin_data.together')) ?></span><?php endif; ?>
            </td>
            <td data-col="cast"><?= e((string) $r['cast_names']) ?></td>
            <td data-col="status"><span class="badge"><?= e(code_label('collab_status', $r['status'])) ?></span></td>
            <td data-col="proposed" data-sort="<?= e(sort_key($r['proposed_for'])) ?>"><?= e($r['proposed_for'] ? fmt_date($r['proposed_for']) : '—') ?></td>
            <td data-col="platform"><?= e($r['platform'] ? code_label('streaming_platform', $r['platform']) : '') ?></td>
            <td data-col="content" data-sort="<?= (int) $r['content_count'] ?>"><?= (int) $r['content_count'] ?></td>
            <td data-col="created" data-sort="<?= e(sort_key($r['created_at'])) ?>"><?= e(fmt_date($r['created_at'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
