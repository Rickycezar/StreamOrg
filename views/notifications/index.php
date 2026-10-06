<?php
/**
 * Every notification sent to the user, newest first, in their language.
 *
 * @var list<array> $items @var bool $more
 */
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.notifications')) ?></h1>
</div>

<?php if ($items === []): ?>
    <section class="card notify-page-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <p><?= e(__('ui.message.notifications_empty')) ?></p>
    </section>
<?php else: ?>
    <ul class="note-page">
        <?php foreach ($items as $item): ?>
            <li id="n-<?= (int) $item['id'] ?>" class="card note<?= $item['unread'] ? ' unread' : '' ?>">
                <?= View::partial('notifications/item', ['item' => $item, 'full' => true]) ?>
                <?php if (!empty($item['link_url'])): ?>
                    <a class="btn small note-link" href="<?= e(url('/notifications/open?id=' . (int) $item['id'])) ?>"
                       <?= str_starts_with((string) $item['link_url'], '/') ? '' : 'data-turbo="false"' ?>><?= e(__('ui.action.open_notification_link')) ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($more): ?>
        <p class="center"><a class="btn" href="<?= e(url('/notifications?before=' . (int) end($items)['id'])) ?>"><?= e(__('ui.action.older')) ?></a></p>
    <?php endif; ?>
<?php endif; ?>
