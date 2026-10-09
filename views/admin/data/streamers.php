<?php
/** Every account's streamer list, with channels and whether they use StreamOrg. @var list<array> $rows @var callable $userLink */
?>
<table data-sortable data-table="admin_data_streamers">
    <thead>
    <tr>
        <th data-col="user"><?= e(__('ui.admin_data.user')) ?></th>
        <th data-col="name"><?= e(__('ui.field.name')) ?></th>
        <th data-col="channels"><?= e(__('ui.admin_data.channels')) ?></th>
        <th data-col="type"><?= e(__('ui.admin_data.broadcaster_type')) ?></th>
        <th data-col="collabs"><?= e(__('ui.nav.collabs')) ?></th>
        <th data-col="created"><?= e(__('ui.admin_data.created')) ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td data-col="user"><a href="<?= e($userLink($r)) ?>"><?= e($r['user_name']) ?></a></td>
            <td data-col="name">
                <?= e($r['name']) ?>
                <?php if ($r['is_favorite']): ?><span title="<?= e(__('ui.admin_data.favorite')) ?>">★</span><?php endif; ?>
                <?php if ($r['on_streamorg']): ?><span class="badge ok"><?= e(__('ui.admin_data.on_streamorg')) ?></span><?php endif; ?>
            </td>
            <td data-col="channels"><?= e((string) $r['channels']) ?></td>
            <td data-col="type"><?= e((string) $r['broadcaster_type']) ?></td>
            <td data-col="collabs" data-sort="<?= (int) $r['collab_count'] ?>"><?= (int) $r['collab_count'] ?></td>
            <td data-col="created" data-sort="<?= e(sort_key($r['created_at'])) ?>"><?= e(fmt_date($r['created_at'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
