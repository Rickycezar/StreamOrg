<?php
/**
 * Support: the three ways to reach the administrators (report a bug,
 * answer a questionnaire, send a message), and below them everything the
 * user already sent, with what is new since they last looked.
 *
 * @var list<array> $bugs @var list<array> $threads @var list<array> $surveys
 * @var array{surveys:int, bugs:int, messages:int, total:int} $attention
 */
$icon = static fn (string $d): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
$toAnswer = array_values(array_filter($surveys, static fn (array $s): bool => $s['answerable'] && $s['submitted_at'] === null));
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.support')) ?></h1>
</div>
<p class="muted support-lead"><?= e(__('ui.support.lead')) ?></p>

<section class="support-ways">
    <a class="support-way way-bug" href="<?= e(url('/support/bug')) ?>">
        <span class="support-way-icon"><?= $icon('M8 6V4.5a4 4 0 0 1 8 0V6M5 10h14M6 6h12a1 1 0 0 1 1 1v6a7 7 0 0 1-14 0V7a1 1 0 0 1 1-1zM12 13v6M2 13h3M19 13h3M3 19l3-2M21 19l-3-2M3 7l3 2M21 7l-3 2') ?></span>
        <strong><?= e(__('ui.support.way_bug')) ?></strong>
        <span><?= e(__('ui.support.way_bug_text')) ?></span>
    </a>
    <a class="support-way way-survey" href="#surveys">
        <span class="support-way-icon"><?= $icon('M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12l2 2 4-4M9 17h6') ?></span>
        <strong><?= e(__('ui.support.way_survey')) ?></strong>
        <span><?= e($toAnswer !== [] ? sprintf(__('ui.support.way_survey_waiting'), count($toAnswer)) : __('ui.support.way_survey_text')) ?></span>
        <?php if ($toAnswer !== []): ?><span class="support-way-badge"><?= count($toAnswer) ?></span><?php endif; ?>
    </a>
    <a class="support-way way-feedback" href="<?= e(url('/support/feedback')) ?>">
        <span class="support-way-icon"><?= $icon('M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12zM8.5 10h7M8.5 13.5h4.5') ?></span>
        <strong><?= e(__('ui.support.way_feedback')) ?></strong>
        <span><?= e(__('ui.support.way_feedback_text')) ?></span>
    </a>
</section>

<section class="card" id="surveys">
    <div class="card-head">
        <h2><?= e(__('ui.support.surveys_title')) ?></h2>
        <?php if ($attention['surveys'] > 0): ?><span class="badge warn"><?= e(sprintf(__('ui.support.n_to_answer'), $attention['surveys'])) ?></span><?php endif; ?>
    </div>
    <?php if ($surveys === []): ?>
        <p class="empty"><?= e(__('ui.support.surveys_none')) ?></p>
    <?php else: ?>
        <ul class="support-list">
            <?php foreach ($surveys as $s):
                $pending = $s['answerable'] && $s['submitted_at'] === null; ?>
                <li class="<?= $pending ? 'is-new' : '' ?>">
                    <a href="<?= e(url(Surveys::LINK . (int) $s['id'])) ?>">
                        <span class="support-item-main">
                            <strong><?= e($s['title']) ?></strong>
                            <small class="muted">
                                <?= e(sprintf(__('ui.support.n_questions'), (int) $s['questions'])) ?>
                                <?php if ($s['closes_at'] && $s['answerable']): ?> · <?= e(sprintf(__('ui.support.closes_on'), fmt_datetime($s['closes_at'], 'd/m H:i'))) ?><?php endif; ?>
                            </small>
                        </span>
                        <span class="badge <?= $pending ? 'warn' : ($s['answerable'] ? 'ok' : 'off') ?>">
                            <?= e(__($pending ? 'ui.support.survey_to_answer' : ($s['answerable'] ? 'ui.support.survey_answered' : 'ui.support.survey_closed'))) ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<div class="support-columns">
    <section class="card">
        <div class="card-head">
            <h2><?= e(__('ui.support.my_bugs')) ?> (<?= count($bugs) ?>)</h2>
            <a class="btn small" href="<?= e(url('/support/bug')) ?>">+ <?= e(__('ui.support.way_bug')) ?></a>
        </div>
        <?php if ($bugs === []): ?>
            <p class="empty"><?= e(__('ui.support.bugs_none')) ?></p>
        <?php else: ?>
            <ul class="support-list">
                <?php foreach ($bugs as $b): ?>
                    <li class="<?= $b['unread'] ? 'is-new' : '' ?>">
                        <a href="<?= e(url(BugReports::LINK . (int) $b['id'])) ?>">
                            <span class="support-item-main">
                                <strong><span class="muted">#<?= (int) $b['id'] ?></span> <?= e($b['title']) ?></strong>
                                <small class="muted">
                                    <?= e(sprintf(__('ui.support.updated_on'), fmt_datetime($b['updated_at'], 'd/m H:i'))) ?>
                                    <?php if ((int) $b['replies'] > 0): ?> · <?= e(sprintf(__('ui.support.n_replies'), (int) $b['replies'])) ?><?php endif; ?>
                                </small>
                            </span>
                            <?php if ($b['unread']): ?><span class="support-dot" title="<?= e(__('ui.support.news')) ?>"></span><?php endif; ?>
                            <span class="badge bug-<?= e($b['status']) ?>"><?= e(__('ui.bug_status.' . $b['status'])) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <h2><?= e(__('ui.support.my_messages')) ?> (<?= count($threads) ?>)</h2>
            <a class="btn small" href="<?= e(url('/support/feedback')) ?>">+ <?= e(__('ui.support.way_feedback')) ?></a>
        </div>
        <?php if ($threads === []): ?>
            <p class="empty"><?= e(__('ui.support.messages_none')) ?></p>
        <?php else: ?>
            <ul class="support-list">
                <?php foreach ($threads as $t): ?>
                    <li class="<?= $t['unread'] ? 'is-new' : '' ?>">
                        <a href="<?= e(url(Feedback::LINK . (int) $t['id'])) ?>">
                            <span class="support-item-main">
                                <strong><?= e($t['subject']) ?></strong>
                                <small class="muted"><?= e(__('ui.feedback_category.' . $t['category'])) ?> · <?= e(fmt_datetime($t['last_message_at'], 'd/m H:i')) ?></small>
                            </span>
                            <?php if ($t['unread']): ?><span class="support-dot" title="<?= e(__('ui.support.news')) ?>"></span><?php endif; ?>
                            <span class="badge <?= $t['answered'] ? 'ok' : 'off' ?>"><?= e(__($t['answered'] ? 'ui.support.answered' : 'ui.support.waiting_answer')) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
