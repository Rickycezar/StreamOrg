<?php
/**
 * Administration → Feedback, like an email inbox: boxes (inbox, unread,
 * starred, archived) and categories on the left, the threads in the middle
 * (unread in bold, with the start of the last message), and the open
 * thread on the right with its replies, a reply box and the thread's
 * actions. On a phone the list and the thread take turns.
 *
 * @var list<array> $threads @var array<string,int> $counts @var ?array $thread
 * @var string $box @var string $category @var string $query
 */
$keep = static fn (array $change): string => url('/admin/feedback?' . http_build_query(array_filter(array_merge(['box' => $box === 'inbox' ? null : $box, 'category' => $category, 'q' => $query], $change))));
$hidden = static function () use ($box, $category, $query): string {
    return '<input type="hidden" name="box" value="' . e($box) . '"><input type="hidden" name="category" value="' . e($category) . '"><input type="hidden" name="q" value="' . e($query) . '">';
};
$boxes = ['inbox' => 'M3 13h5l2 3h4l2-3h5M5 5h14l2 8v6H3v-6z', 'unread' => 'M4 6h16v12H4zM4 7l8 6 8-6', 'starred' => 'M12 3l2.6 5.6 6 .7-4.5 4.1 1.2 6-5.3-3-5.3 3 1.2-6L3.4 9.3l6-.7z', 'archived' => 'M3 4h18v4H3zM5 8v12h14V8M10 12h4'];
$icon  = static fn (string $d): string => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
?>
<h1><?= e(__('ui.nav.feedback_inbox')) ?></h1>

<div class="inbox<?= $thread ? ' has-thread' : '' ?>">
    <nav class="inbox-boxes" aria-label="<?= e(__('ui.support.boxes')) ?>">
        <?php foreach ($boxes as $name => $path): ?>
            <a href="<?= e($keep(['box' => $name === 'inbox' ? null : $name, 'id' => null])) ?>" class="<?= $box === $name ? 'active' : '' ?>">
                <?= $icon($path) ?><span><?= e(__('ui.support.box_' . $name)) ?></span>
                <?php if ($counts[$name] > 0 && $name !== 'archived'): ?><span class="inbox-count"><?= (int) $counts[$name] ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
        <span class="inbox-label"><?= e(__('ui.support.f_category')) ?></span>
        <a href="<?= e($keep(['category' => null, 'id' => null])) ?>" class="<?= $category === '' ? 'active' : '' ?>"><span><?= e(__('ui.label.any')) ?></span></a>
        <?php foreach (Feedback::CATEGORIES as $c): ?>
            <a href="<?= e($keep(['category' => $c, 'id' => null])) ?>" class="<?= $category === $c ? 'active' : '' ?>"><span class="cat-dot cat-<?= e($c) ?>"></span><span><?= e(__('ui.feedback_category.' . $c)) ?></span></a>
        <?php endforeach; ?>
    </nav>

    <section class="inbox-list card">
        <form method="get" action="<?= e(url('/admin/feedback')) ?>" class="inbox-search">
            <?php if ($box !== 'inbox'): ?><input type="hidden" name="box" value="<?= e($box) ?>"><?php endif; ?>
            <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= e($query) ?>" placeholder="<?= e(__('ui.support.inbox_search')) ?>" aria-label="<?= e(__('ui.action.search')) ?>">
        </form>
        <?php if ($threads === []): ?>
            <p class="empty"><?= e(__('ui.support.inbox_empty')) ?></p>
        <?php else: ?>
            <ul>
                <?php foreach ($threads as $t): ?>
                    <li class="<?= $t['admin_unread'] ? 'unread' : '' ?><?= $thread && (int) $thread['id'] === (int) $t['id'] ? ' current' : '' ?>">
                        <a href="<?= e($keep(['id' => (int) $t['id']])) ?>">
                            <?php $avatarUser = $t; $avatarSize = 'sm'; require dirname(__DIR__) . '/partials/avatar.php'; ?>
                            <span class="inbox-item">
                                <span class="inbox-item-top">
                                    <strong><?= e((string) $t['author']) ?></strong>
                                    <?php if ((int) $t['messages'] > 1): ?><small class="muted">(<?= (int) $t['messages'] ?>)</small><?php endif; ?>
                                    <?php if ($t['is_starred']): ?><span class="inbox-star" aria-label="<?= e(__('ui.support.box_starred')) ?>">★</span><?php endif; ?>
                                    <time class="muted"><?= e(fmt_datetime($t['last_message_at'], 'd/m H:i')) ?></time>
                                </span>
                                <span class="inbox-subject"><span class="cat-dot cat-<?= e($t['category']) ?>" title="<?= e(__('ui.feedback_category.' . $t['category'])) ?>"></span><?= e($t['subject']) ?></span>
                                <span class="inbox-snippet muted"><?= $t['last_from_admin'] ? e(__('ui.support.you_replied')) . ' ' : '' ?><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $t['last_body']), 0, 110, '…')) ?></span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="inbox-thread card">
        <?php if ($thread === null): ?>
            <div class="inbox-pick">
                <?= $icon($boxes['inbox']) ?>
                <p class="muted"><?= e(__('ui.support.inbox_pick')) ?></p>
            </div>
        <?php else: ?>
            <header class="inbox-thread-head">
                <a class="btn small inbox-back" href="<?= e($keep(['id' => null])) ?>">← <?= e(__('ui.support.box_' . $box)) ?></a>
                <div>
                    <h2><?= e($thread['subject']) ?></h2>
                    <p class="muted small">
                        <span class="badge"><?= e(__('ui.feedback_category.' . $thread['category'])) ?></span>
                        <?= e((string) $thread['author']) ?><?= $thread['email'] ? ' · ' . e((string) $thread['email']) : '' ?>
                    </p>
                </div>
                <div class="inbox-actions">
                    <?php foreach ([['star', !$thread['is_starred'], $thread['is_starred'] ? 'unstar' : 'star'], ['unread', true, 'mark_unread'], ['archive', !$thread['is_archived'], $thread['is_archived'] ? 'unarchive' : 'archive']] as [$flag, $on, $label]): ?>
                        <form method="post" action="<?= e(url('/admin/feedback/flag')) ?>">
                            <?= Csrf::field() ?><?= $hidden() ?>
                            <input type="hidden" name="id" value="<?= (int) $thread['id'] ?>">
                            <input type="hidden" name="flag" value="<?= e($flag) ?>">
                            <input type="hidden" name="on" value="<?= $on ? '1' : '0' ?>">
                            <button type="submit" class="btn small"><?= e(__('ui.support.thread_' . $label)) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </header>

            <div class="chat inbox-chat">
                <?php foreach ($thread['messages'] as $m): ?>
                    <article class="chat-message<?= $m['from_admin'] ? ' mine' : ' from-user' ?>">
                        <header>
                            <strong><?= e((string) $m['author']) ?></strong>
                            <?php if ($m['from_admin']): ?><span class="badge"><?= e(__('ui.support.team')) ?></span><?php endif; ?>
                            <time class="muted" datetime="<?= e($m['created_at']) ?>"><?= e(fmt_datetime($m['created_at'], 'd/m/Y H:i')) ?></time>
                        </header>
                        <p><?= nl2br(e($m['body'])) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>

            <form method="post" action="<?= e(url('/admin/feedback/reply')) ?>" class="inbox-reply">
                <?= Csrf::field() ?><?= $hidden() ?>
                <input type="hidden" name="id" value="<?= (int) $thread['id'] ?>">
                <textarea name="body" rows="4" required maxlength="5000" placeholder="<?= e(sprintf(__('ui.support.reply_to'), (string) $thread['author'])) ?>" aria-label="<?= e(__('ui.support.reply')) ?>"></textarea>
                <div class="inbox-reply-foot">
                    <small class="muted"><?= e(__('ui.support.reply_notified')) ?></small>
                    <button type="submit" class="btn primary"><?= e(__('ui.support.reply_send')) ?></button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>
