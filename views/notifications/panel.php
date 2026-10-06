<?php
/**
 * The bell's dropdown: the latest notifications, a way to mark them all
 * read, and the full list.
 *
 * @var list<array> $items @var int $unread
 */
?>
<div class="notify-head">
    <strong><?= e(__('ui.nav.notifications')) ?></strong>
    <?php if ($unread > 0): ?>
        <button type="button" class="link-button" data-notify-read-all><?= e(__('ui.action.mark_all_read')) ?></button>
    <?php endif; ?>
</div>
<?php if ($items === []): ?>
    <div class="notify-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <p><?= e(__('ui.message.notifications_empty')) ?></p>
    </div>
<?php else: ?>
    <ul class="notify-list">
        <?php foreach ($items as $item): ?>
            <li>
                <a class="note<?= $item['unread'] ? ' unread' : '' ?>" href="<?= e(url('/notifications/open?id=' . (int) $item['id'])) ?>"
                   <?= !empty($item['link_url']) && !str_starts_with((string) $item['link_url'], '/') ? 'data-turbo="false"' : '' ?>>
                    <?= View::partial('notifications/item', ['item' => $item, 'full' => false]) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<a class="notify-all" href="<?= e(url('/notifications')) ?>"><?= e(__('ui.action.see_all_notifications')) ?></a>
