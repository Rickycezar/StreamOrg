<?php
/**
 * A bug report as its reporter follows it: where it stands, what they
 * reported, the conversation with the team, and what they can do now —
 * confirm a fix (or say it still happens), answer a question, add details.
 *
 * @var array $bug @var list<array> $timeline @var array<int, list<array>> $images @var bool $admin
 */
$open = in_array($bug['status'], BugReports::OPEN, true);
?>
<div class="page-head">
    <h1><span class="muted">#<?= (int) $bug['id'] ?></span> <?= e($bug['title']) ?></h1>
    <a class="btn small" href="<?= e(url('/support')) ?>">← <?= e(__('ui.nav.support')) ?></a>
</div>

<?php if ($bug['status'] === 'fixed'): ?>
    <section class="card bug-callout callout-fixed">
        <h2><?= e(__('ui.support.fixed_title')) ?></h2>
        <p><?= e(__('ui.support.fixed_text')) ?></p>
        <div class="bug-fix-answer">
            <form method="post" action="<?= e(url('/support/bug/fix')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $bug['id'] ?>">
                <input type="hidden" name="works" value="1">
                <button type="submit" class="btn primary"><?= e(__('ui.support.fix_works')) ?></button>
            </form>
            <details class="bug-still">
                <summary class="btn"><?= e(__('ui.support.fix_still')) ?></summary>
                <form method="post" action="<?= e(url('/support/bug/fix')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $bug['id'] ?>">
                    <input type="hidden" name="works" value="0">
                    <label>
                        <span><?= e(__('ui.support.fix_still_what')) ?></span>
                        <textarea name="body" rows="3" maxlength="5000" required></textarea>
                    </label>
                    <button type="submit" class="btn"><?= e(__('ui.support.fix_still_send')) ?></button>
                </form>
            </details>
        </div>
    </section>
<?php elseif ($bug['status'] === 'need_info'): ?>
    <section class="card bug-callout callout-info">
        <h2><?= e(__('ui.support.need_info_title')) ?></h2>
        <p><?= e(__('ui.support.need_info_text')) ?></p>
        <a class="btn primary" href="#reply"><?= e(__('ui.support.need_info_answer')) ?></a>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/_report.php'; ?>

<?php if ($open || $bug['status'] === 'fixed'): ?>
    <form method="post" action="<?= e(url('/support/bug/comment')) ?>" class="card bug-reply" id="reply">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $bug['id'] ?>">
        <label>
            <span><?= e(__($bug['status'] === 'need_info' ? 'ui.support.reply_answer' : 'ui.support.reply_add')) ?></span>
            <textarea name="body" rows="3" maxlength="5000" placeholder="<?= e(__('ui.support.reply_hint')) ?>"></textarea>
        </label>
        <?php $compact = true; require __DIR__ . '/_uploader.php'; ?>
        <button type="submit" class="btn primary"><?= e(__('ui.support.reply_send')) ?></button>
    </form>
<?php else: ?>
    <p class="muted center"><?= e(__('ui.support.report_closed')) ?> <a href="<?= e(url('/support/bug')) ?>"><?= e(__('ui.support.report_new')) ?></a></p>
<?php endif; ?>
