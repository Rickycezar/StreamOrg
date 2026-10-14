<?php
/**
 * A page of channel events (Events and Unfollows): when, what, who and
 * the details, then the pages. Refreshed in place every half minute on
 * the first page.
 *
 * @var list<array> $rows @var int $total @var int $page @var string $base the page's address, without page
 * @var string $empty the message when there is nothing
 */
$pages = max(1, (int) ceil($total / ChannelEvents::PER_PAGE));
$link  = static fn (int $p): string => url($base . (str_contains($base, '?') ? '&' : '?') . 'page=' . $p);
?>
<div class="event-list" <?= $page === 1 ? 'data-auto-refresh="30"' : '' ?>>
    <?php if ($rows === []): ?>
        <p class="empty"><?= e($empty) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="events-table">
                <thead>
                <tr>
                    <th><?= e(__('ui.field.when')) ?></th>
                    <th><?= e(__('ui.field.event')) ?></th>
                    <th><?= e(__('ui.field.who')) ?></th>
                    <th><?= e(__('ui.field.details')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $event): $who = $event['actor_name'] ?: ($event['actor_login'] ?: __('ui.channel_event.anonymous')); ?>
                    <tr class="event-<?= e($event['type']) ?>">
                        <td class="nowrap muted" data-label="<?= e(__('ui.field.when')) ?>"><?= e(fmt_datetime($event['at'], 'd/m H:i')) ?></td>
                        <td data-label="<?= e(__('ui.field.event')) ?>"><span class="badge event-badge event-badge-<?= e($event['type']) ?>"><?= e(__('ui.channel_event.' . $event['type'])) ?></span></td>
                        <td data-card-title data-label="<?= e(__('ui.field.who')) ?>">
                            <?php if ($event['actor_login']): ?>
                                <a href="https://www.twitch.tv/<?= e(rawurlencode((string) $event['actor_login'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($who) ?></a>
                            <?php else: ?><?= e($who) ?><?php endif; ?>
                        </td>
                        <td data-label="<?= e(__('ui.field.details')) ?>">
                            <?= e(ChannelEvents::details($event)) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="<?= e(__('ui.admin_data.pages')) ?>">
                <?php if ($page > 1): ?><a class="btn small" href="<?= e($link($page - 1)) ?>">← <?= e(__('ui.admin_data.previous')) ?></a><?php endif; ?>
                <span class="muted small"><?= e(sprintf(__('ui.admin_data.page_of'), $page, $pages)) ?></span>
                <?php if ($page < $pages): ?><a class="btn small" href="<?= e($link($page + 1)) ?>"><?= e(__('ui.admin_data.next')) ?> →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
