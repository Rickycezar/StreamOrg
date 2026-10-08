<?php
/**
 * A message to the administrators and its replies, as a conversation: the
 * user's messages on one side, the team's on the other, and a reply box.
 *
 * @var array $thread with messages
 */
?>
<div class="page-head">
    <h1><?= e($thread['subject']) ?></h1>
    <a class="btn small" href="<?= e(url('/support')) ?>">← <?= e(__('ui.nav.support')) ?></a>
</div>
<p class="muted"><span class="badge"><?= e(__('ui.feedback_category.' . $thread['category'])) ?></span> <?= e(sprintf(__('ui.support.sent_on'), fmt_datetime($thread['created_at']))) ?></p>

<section class="chat">
    <?php foreach ($thread['messages'] as $m): ?>
        <article class="chat-message<?= $m['from_admin'] ? ' from-team' : ' mine' ?>">
            <header>
                <strong><?= e($m['from_admin'] ? (string) $m['author'] : __('ui.support.you')) ?></strong>
                <?php if ($m['from_admin']): ?><span class="badge"><?= e(__('ui.support.team')) ?></span><?php endif; ?>
                <time class="muted" datetime="<?= e($m['created_at']) ?>"><?= e(fmt_datetime($m['created_at'], 'd/m H:i')) ?></time>
            </header>
            <p><?= nl2br(e($m['body'])) ?></p>
        </article>
    <?php endforeach; ?>
</section>

<form method="post" action="<?= e(url('/support/message/reply')) ?>" class="card chat-reply">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $thread['id'] ?>">
    <label>
        <span><?= e(__('ui.support.reply')) ?></span>
        <textarea name="body" rows="3" required maxlength="5000"></textarea>
    </label>
    <button type="submit" class="btn primary"><?= e(__('ui.support.reply_send')) ?></button>
</form>
