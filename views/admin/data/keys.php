<?php
/** Every account's keys: what each key is, never its code or notes. @var list<array> $rows @var callable $userLink */
?>
<table data-sortable data-table="admin_data_keys">
    <thead>
    <tr>
        <th data-col="user"><?= e(__('ui.admin_data.user')) ?></th>
        <th data-col="game"><?= e(__('ui.field.game')) ?></th>
        <th data-col="platform"><?= e(__('ui.field.platform')) ?></th>
        <th data-col="key_site"><?= e(__('ui.admin_data.key_site')) ?></th>
        <th data-col="type"><?= e(__('ui.field.key_type')) ?></th>
        <th data-col="content_type"><?= e(__('ui.field.content_type')) ?></th>
        <th data-col="status"><?= e(__('ui.field.status')) ?></th>
        <th data-col="source"><?= e(__('ui.field.source')) ?></th>
        <th data-col="region"><?= e(__('ui.admin_data.region')) ?></th>
        <th data-col="received"><?= e(__('ui.admin_data.received')) ?></th>
        <th data-col="expires"><?= e(__('ui.admin_data.expires')) ?></th>
        <th data-col="created"><?= e(__('ui.admin_data.created')) ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td data-col="user" data-label="<?= e(__('ui.admin_data.user')) ?>"><a href="<?= e($userLink($r)) ?>"><?= e($r['user_name']) ?></a></td>
            <td data-card-title data-col="game" data-label="<?= e(__('ui.field.game')) ?>">
                <?= e($r['game']) ?>
                <?php if ($r['is_placeholder']): ?><span class="badge off"><?= e(__('ui.admin_data.placeholder')) ?></span><?php endif; ?>
                <?php if ($r['in_content']): ?><span class="badge"><?= e(__('ui.admin_data.in_content')) ?></span><?php endif; ?>
            </td>
            <td data-col="platform" data-label="<?= e(__('ui.field.platform')) ?>"><?= e($r['game_platform'] ? code_label('game_platform', $r['game_platform']) : '') ?></td>
            <td data-col="key_site" data-label="<?= e(__('ui.admin_data.key_site')) ?>"><?= e($r['key_platform'] ? code_label('key_platform', $r['key_platform']) : '') ?></td>
            <td data-col="type" data-label="<?= e(__('ui.field.key_type')) ?>"><?= e($r['key_type'] ? code_label('key_type', $r['key_type']) : '') ?></td>
            <td data-col="content_type" data-label="<?= e(__('ui.field.content_type')) ?>"><?= e($r['content_type'] ? code_label('content_type', $r['content_type']) : '') ?></td>
            <td data-col="status" data-label="<?= e(__('ui.field.status')) ?>"><span class="badge"><?= e(code_label('key_status', $r['status'])) ?></span></td>
            <td data-col="source" data-label="<?= e(__('ui.field.source')) ?>"><?= e((string) $r['source_note']) ?></td>
            <td data-col="region" data-label="<?= e(__('ui.admin_data.region')) ?>"><?= e((string) $r['region']) ?></td>
            <td data-col="received" data-sort="<?= e(sort_key($r['received_at'])) ?>" data-label="<?= e(__('ui.admin_data.received')) ?>"><?= e($r['received_at'] ? fmt_date($r['received_at']) : '—') ?></td>
            <td data-col="expires" data-sort="<?= e(sort_key($r['expires_at'])) ?>" data-label="<?= e(__('ui.admin_data.expires')) ?>"><?= e($r['expires_at'] ? fmt_date($r['expires_at']) : '—') ?></td>
            <td data-col="created" data-sort="<?= e(sort_key($r['created_at'])) ?>" data-label="<?= e(__('ui.admin_data.created')) ?>"><?= e(fmt_date($r['created_at'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
