<?php
/**
 * Stream tools → Events: what the chat bot recorded in the channel, by
 * kind, newest first.
 *
 * @var list<array> $rows @var int $total @var int $page @var ?string $filter @var array<string,int> $counts
 * @var ?array $channel the bot in this channel @var bool $follows whether follows can be seen
 */
$reading = $channel !== null && $channel['is_enabled'] && !$channel['is_blocked'] && $channel['access'] !== 'none';
?>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.events')) ?></h2>
    </div>
    <p class="muted small"><?= e(__('ui.message.events_intro')) ?></p>

    <?php if (!$reading): ?>
        <p class="flash flash-warning"><?= e(__('ui.message.events_no_bot')) ?> <a href="<?= e(url('/bot')) ?>"><?= e(__('ui.nav.chat_bot')) ?> →</a></p>
    <?php elseif (!$follows): ?>
        <p class="flash flash-warning"><?= e(__('ui.message.events_no_follows')) ?> <a href="<?= e(url('/profile/twitch/connect')) ?>" data-turbo="false"><?= e(__('ui.action.twitch_reconnect')) ?> →</a></p>
    <?php endif; ?>

    <nav class="chip-filter" aria-label="<?= e(__('ui.action.filter')) ?>">
        <a href="<?= e(url('/events')) ?>" class="chip<?= $filter === null ? ' active' : '' ?>"><?= e(__('ui.label.all_events')) ?></a>
        <?php foreach (ChannelEvents::FILTERS as $name => $types): ?>
            <a href="<?= e(url('/events?type=' . $name)) ?>" class="chip<?= $filter === $name ? ' active' : '' ?>">
                <?= e(__('ui.channel_event.filter_' . $name)) ?><?php if ($counts[$name] > 0): ?> <span class="chip-count"><?= (int) $counts[$name] ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?= View::partial('events/_list', [
        'rows'  => $rows,
        'total' => $total,
        'page'  => $page,
        'base'  => '/events' . ($filter !== null ? '?type=' . $filter : ''),
        'empty' => __('ui.message.events_none'),
    ]) ?>
</section>
