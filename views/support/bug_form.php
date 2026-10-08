<?php
/**
 * Reporting a bug, step by step: a short title, the page, what happened
 * and what was expected, the steps to make it happen (one per line, added
 * as needed), screenshots with pins, and how much it gets in the way.
 * What the browser says about itself is sent too, and the form says so.
 *
 * When the user comes from an error page, the report starts with that
 * error: a title naming it, and its code and reference sent along.
 *
 * @var string $page the page it is about (the one the user came from) @var array<string,string> $pages path => name
 * @var array{code:int, ref:?int}|null $error the error the user just saw
 */
$old = $_SESSION['old_bug'] ?? [];
unset($_SESSION['old_bug']);
$steps  = array_values(array_filter((array) ($old['steps'] ?? []), static fn ($s): bool => trim((string) $s) !== '')) ?: ['', ''];
$impact = (string) ($old['impact'] ?? 'annoying');
$icons  = [
    'blocking' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM5.6 5.6l12.8 12.8',
    'annoying' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 15s1.5-1.5 4-1.5 4 1.5 4 1.5M9 9.5h.01M15 9.5h.01',
    'cosmetic' => 'M12 3l2.4 5 5.6.8-4 4 1 5.6-5-2.7-5 2.7 1-5.6-4-4 5.6-.8z',
];
?>
<div class="page-head">
    <h1><?= e(__('ui.support.bug_title')) ?></h1>
    <a class="btn small" href="<?= e(url('/support')) ?>">← <?= e(__('ui.nav.support')) ?></a>
</div>
<p class="muted support-lead"><?= e(__('ui.support.bug_lead')) ?></p>
<?php if ($error !== null): ?>
    <p class="notice bug-from-error"><?= e(sprintf(__('ui.error_page.form_notice'), $error['code'])) ?><?php if ($error['ref']): ?> <?= e(__('ui.error_page.reference')) ?> <code>#<?= (int) $error['ref'] ?></code><?php endif; ?></p>
<?php endif; ?>

<form method="post" action="<?= e(url('/support/bug')) ?>" class="card bug-form" data-bug-form>
    <?= Csrf::field() ?>

    <div class="bug-step">
        <span class="bug-step-num">1</span>
        <div class="bug-step-body">
            <label>
                <span><?= e(__('ui.support.q_title')) ?></span>
                <input type="text" name="title" required minlength="3" maxlength="140" value="<?= e((string) ($old['title'] ?? ($error !== null ? sprintf(__('ui.error_page.bug_title'), $error['code'], $page !== '' ? $page : '/') : ''))) ?>"
                       placeholder="<?= e(__('ui.support.q_title_hint')) ?>" autofocus>
            </label>
        </div>
    </div>

    <div class="bug-step">
        <span class="bug-step-num">2</span>
        <div class="bug-step-body">
            <label>
                <span><?= e(__('ui.support.q_page')) ?></span>
                <select name="page" data-bug-page>
                    <option value=""><?= e(__('ui.support.q_page_none')) ?></option>
                    <?php $known = false; foreach ($pages as $path => $name): $on = ($old['page'] ?? $page) === $path; $known = $known || $on; ?>
                        <option value="<?= e($path) ?>" <?= $on ? 'selected' : '' ?>><?= e($name) ?> (<?= e($path) ?>)</option>
                    <?php endforeach; ?>
                    <?php $other = (string) ($old['page'] ?? $page); if (!$known && $other !== ''): ?>
                        <option value="<?= e($other) ?>" selected><?= e($other) ?></option>
                    <?php endif; ?>
                </select>
                <small class="muted"><?= e(__('ui.support.q_page_hint')) ?></small>
            </label>
        </div>
    </div>

    <div class="bug-step">
        <span class="bug-step-num">3</span>
        <div class="bug-step-body">
            <label>
                <span><?= e(__('ui.support.q_what')) ?></span>
                <textarea name="description" rows="4" required maxlength="5000" placeholder="<?= e(__('ui.support.q_what_hint')) ?>"><?= e((string) ($old['description'] ?? '')) ?></textarea>
            </label>
            <label>
                <span><?= e(__('ui.support.q_expected')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                <textarea name="expected" rows="2" maxlength="2000" placeholder="<?= e(__('ui.support.q_expected_hint')) ?>"><?= e((string) ($old['expected'] ?? '')) ?></textarea>
            </label>
        </div>
    </div>

    <div class="bug-step">
        <span class="bug-step-num">4</span>
        <div class="bug-step-body">
            <span class="field-label"><?= e(__('ui.support.q_steps')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
            <p class="muted small"><?= e(__('ui.support.q_steps_hint')) ?></p>
            <ol class="bug-steps" data-bug-steps data-placeholder="<?= e(__('ui.support.q_step_placeholder')) ?>" data-remove="<?= e(__('ui.action.delete')) ?>">
                <?php foreach ($steps as $i => $step): ?>
                    <li>
                        <input type="text" name="steps[]" maxlength="500" value="<?= e((string) $step) ?>" placeholder="<?= e(__('ui.support.q_step_example_' . min($i, 2))) ?>">
                        <button type="button" class="bug-step-remove" data-step-remove aria-label="<?= e(__('ui.action.delete')) ?>">×</button>
                    </li>
                <?php endforeach; ?>
            </ol>
            <button type="button" class="btn small" data-step-add>+ <?= e(__('ui.support.q_step_add')) ?></button>
        </div>
    </div>

    <div class="bug-step">
        <span class="bug-step-num">5</span>
        <div class="bug-step-body">
            <span class="field-label"><?= e(__('ui.support.q_shots')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
            <?php require __DIR__ . '/_uploader.php'; ?>
        </div>
    </div>

    <div class="bug-step">
        <span class="bug-step-num">6</span>
        <div class="bug-step-body">
            <span class="field-label"><?= e(__('ui.support.q_impact')) ?></span>
            <div class="impact-choices" role="radiogroup">
                <?php foreach (BugReports::IMPACTS as $option): ?>
                    <label class="impact-choice impact-<?= e($option) ?>">
                        <input type="radio" name="impact" value="<?= e($option) ?>" <?= $impact === $option ? 'checked' : '' ?>>
                        <span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= e($icons[$option]) ?>"/></svg>
                            <strong><?= e(__('ui.bug_impact.' . $option)) ?></strong>
                            <small><?= e(__('ui.support.impact_' . $option . '_hint')) ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php foreach (['screen', 'viewport', 'language', 'timezone', 'theme', 'platform'] as $key): ?>
        <input type="hidden" name="env[<?= e($key) ?>]" data-env="<?= e($key) ?>">
    <?php endforeach; ?>
    <?php if ($error !== null): ?>
        <input type="hidden" name="env[error_code]" value="<?= (int) $error['code'] ?>">
        <?php if ($error['ref']): ?><input type="hidden" name="env[error_ref]" value="<?= (int) $error['ref'] ?>"><?php endif; ?>
    <?php endif; ?>

    <div class="bug-submit">
        <p class="muted small"><?= e(__('ui.support.env_note')) ?></p>
        <button type="submit" class="btn primary"><?= e(__('ui.support.bug_send')) ?></button>
    </div>
</form>
