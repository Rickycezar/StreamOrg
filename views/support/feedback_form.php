<?php
/**
 * Writing to the administrators: what kind of message it is (an idea,
 * praise, a problem, a question, something else), a subject and the
 * message. Bugs have their own form, which this page points to.
 *
 * @var string $category the one chosen first
 */
$old = $_SESSION['old_feedback'] ?? [];
unset($_SESSION['old_feedback']);
$category = (string) ($old['category'] ?? $category);
$icons = [
    'idea'     => 'M9 18h6M10 21h4M12 3a6 6 0 0 0-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0 0 12 3z',
    'praise'   => 'M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z',
    'problem'  => 'M12 3l9 16H3zM12 10v4M12 17h.01',
    'question' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.6 9.2a2.5 2.5 0 0 1 4.8.8c0 1.7-2.4 2.2-2.4 3.5M12 16.8h.01',
    'other'    => 'M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z',
];
?>
<div class="page-head">
    <h1><?= e(__('ui.support.feedback_title')) ?></h1>
    <a class="btn small" href="<?= e(url('/support')) ?>">← <?= e(__('ui.nav.support')) ?></a>
</div>
<p class="muted support-lead"><?= e(__('ui.support.feedback_lead')) ?></p>

<form method="post" action="<?= e(url('/support/feedback')) ?>" class="card feedback-form">
    <?= Csrf::field() ?>
    <span class="field-label"><?= e(__('ui.support.f_category')) ?></span>
    <div class="category-choices" role="radiogroup">
        <?php foreach (Feedback::CATEGORIES as $option): ?>
            <label class="category-choice cat-<?= e($option) ?>">
                <input type="radio" name="category" value="<?= e($option) ?>" <?= $category === $option ? 'checked' : '' ?>>
                <span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= e($icons[$option]) ?>"/></svg>
                    <?= e(__('ui.feedback_category.' . $option)) ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>
    <p class="muted small feedback-bug-hint"><?= e(__('ui.support.feedback_bug_hint')) ?> <a href="<?= e(url('/support/bug')) ?>"><?= e(__('ui.support.way_bug')) ?></a></p>

    <label>
        <span><?= e(__('ui.support.f_subject')) ?></span>
        <input type="text" name="subject" required maxlength="140" value="<?= e((string) ($old['subject'] ?? '')) ?>" placeholder="<?= e(__('ui.support.f_subject_hint')) ?>">
    </label>
    <label>
        <span><?= e(__('ui.support.f_message')) ?></span>
        <textarea name="body" rows="7" required maxlength="5000" placeholder="<?= e(__('ui.support.f_message_hint')) ?>"><?= e((string) ($old['body'] ?? '')) ?></textarea>
    </label>
    <button type="submit" class="btn primary"><?= e(__('ui.support.feedback_send')) ?></button>
</form>
