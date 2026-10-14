<?php
/**
 * Stream tools → Unfollows: who stopped following, as the daily look at
 * the follower list noticed, and how that look went.
 *
 * @var list<array> $rows @var int $total @var int $page @var ?array $tracking @var bool $follows whether the list can be read
 */
?>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.unfollows')) ?></h2>
    </div>
    <p class="muted small"><?= e(__('ui.message.unfollows_intro')) ?></p>

    <dl class="detail">
        <dt><?= e(__('ui.label.unfollows_checked')) ?></dt>
        <dd>
            <?php if (!empty($tracking['checked_at']) && $tracking['follower_count'] !== null): ?>
                <?= e(fmt_datetime($tracking['checked_at'])) ?> · <?= e(sprintf(__('ui.label.unfollows_followers'), number_format((int) $tracking['follower_count'], 0, ',', '.'))) ?>
            <?php else: ?>
                <span class="muted"><?= e(__('ui.message.unfollows_first')) ?></span>
            <?php endif; ?>
        </dd>
        <?php if (!empty($tracking['last_error'])): ?>
            <dt><?= e(__('ui.label.unfollows_problem')) ?></dt>
            <dd class="danger-text"><?= e((string) $tracking['last_error']) ?></dd>
        <?php endif; ?>
    </dl>

    <?php if (!$follows): ?>
        <p class="flash flash-warning"><?= e(__('ui.message.unfollows_no_permission')) ?> <a href="<?= e(url('/profile/twitch/connect')) ?>" data-turbo="false"><?= e(__('ui.action.twitch_reconnect')) ?> →</a></p>
    <?php endif; ?>

    <?= View::partial('events/_list', [
        'rows'  => $rows,
        'total' => $total,
        'page'  => $page,
        'base'  => '/unfollows',
        'empty' => __('ui.message.unfollows_none'),
    ]) ?>
</section>
