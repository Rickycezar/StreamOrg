<?php
/**
 * Administration → one bug report: the report and its history on the left
 * (as the reporter sees it, plus internal notes and what the browser
 * sent), and on the right what an administrator can do: reply or keep a
 * note, change the status (the reporter is told) and the priority, mark it
 * a duplicate, or mark it fixed with a message.
 *
 * @var array $bug @var list<array> $timeline @var array<int, list<array>> $images @var bool $admin @var int $reports
 */
?>
<div class="page-head">
    <h1><span class="muted">#<?= (int) $bug['id'] ?></span> <?= e($bug['title']) ?></h1>
    <a class="btn small" href="<?= e(url('/admin/bugs')) ?>">← <?= e(__('ui.nav.bug_tracker')) ?></a>
</div>

<div class="bug-admin">
    <div class="bug-admin-main">
        <?php require dirname(__DIR__) . '/support/_report.php'; ?>
    </div>

    <aside class="bug-admin-side">
        <form method="post" action="<?= e(url('/admin/bugs/update')) ?>" class="card bug-manage" data-bug-manage>
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $bug['id'] ?>">
            <h2><?= e(__('ui.support.manage')) ?></h2>

            <div class="bug-quick">
                <?php foreach (['confirmed', 'in_progress', 'need_info', 'fixed'] as $quick): if ($quick === $bug['status']) continue; ?>
                    <button type="button" class="btn small quick-<?= e($quick) ?>" data-bug-quick="<?= e($quick) ?>"><?= e(__('ui.support.quick_' . $quick)) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="grid">
                <label>
                    <span><?= e(__('ui.support.f_status')) ?></span>
                    <select name="status" data-bug-status>
                        <?php foreach (BugReports::STATUSES as $s): ?>
                            <option value="<?= e($s) ?>" <?= $bug['status'] === $s ? 'selected' : '' ?>><?= e(__('ui.bug_status.' . $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?= e(__('ui.support.f_priority')) ?></span>
                    <select name="priority">
                        <?php foreach (BugReports::PRIORITIES as $p): ?>
                            <option value="<?= e($p) ?>" <?= $bug['priority'] === $p ? 'selected' : '' ?>><?= e(__('ui.bug_priority.' . $p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label data-duplicate-field <?= $bug['status'] === 'duplicate' ? '' : 'hidden' ?>>
                <span><?= e(__('ui.support.f_duplicate_of')) ?></span>
                <input type="number" name="duplicate_of" min="1" value="<?= $bug['duplicate_of'] ? (int) $bug['duplicate_of'] : '' ?>" placeholder="#">
            </label>

            <label>
                <span data-body-label data-reply="<?= e(__('ui.support.reply_to_reporter')) ?>" data-note="<?= e(__('ui.support.internal_note')) ?>" data-fixed="<?= e(__('ui.support.fixed_message')) ?>"><?= e(__('ui.support.reply_to_reporter')) ?></span>
                <textarea name="body" rows="5" maxlength="5000" data-bug-body placeholder="<?= e(__('ui.support.reply_hint_admin')) ?>"></textarea>
            </label>
            <label class="inline bug-internal">
                <input type="checkbox" name="internal" value="1" data-bug-internal>
                <span><?= e(__('ui.support.internal_toggle')) ?></span>
            </label>
            <p class="muted small" data-bug-notice><?= e(__('ui.support.reporter_told')) ?></p>

            <?php $compact = true; require dirname(__DIR__) . '/support/_uploader.php'; ?>

            <button type="submit" class="btn primary"><?= e(__('ui.support.save_update')) ?></button>
        </form>

        <?php if (!empty($bug['environment']['error_ref']) || !empty($bug['environment']['error_code'])): ?>
            <section class="card bug-linked-error">
                <h2><?= e(sprintf(__('ui.error_page.linked_title'), (int) ($bug['environment']['error_code'] ?? 500))) ?></h2>
                <p class="muted small"><?= e(__('ui.error_page.linked_text')) ?></p>
                <?php if (!empty($bug['environment']['error_ref'])): ?>
                    <a class="btn small" href="<?= e(url('/admin/errors?id=' . (int) $bug['environment']['error_ref'])) ?>"><?= e(sprintf(__('ui.error_page.open_in_log'), (int) $bug['environment']['error_ref'])) ?></a>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card bug-reporter">
            <h2><?= e(__('ui.support.f_reporter')) ?></h2>
            <?php if ($bug['user_id']): ?>
                <p><strong><?= e((string) $bug['reporter']) ?></strong> <small class="muted">@<?= e((string) $bug['reporter_username']) ?></small></p>
                <p class="muted small"><?= e((string) $bug['reporter_email']) ?></p>
                <p class="muted small"><?= e(sprintf(__('ui.support.n_reports'), $reports)) ?></p>
            <?php else: ?>
                <p class="muted"><?= e(__('ui.support.reporter_gone')) ?></p>
            <?php endif; ?>
        </section>
    </aside>
</div>
