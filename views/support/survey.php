<?php
/**
 * Answering a questionnaire: its sections and questions, each with the
 * input its kind needs, required ones marked, problems shown next to their
 * question, and a bar showing how much is answered. Answers can be changed
 * while it is open; once closed, the page shows what was answered.
 *
 * @var array $survey with groups, in the reader's language (Surveys::localized()) @var array<int, mixed> $answers question id => answer (choice keys)
 * @var array<int, string> $errors @var bool $answerable @var bool $answered
 */
$total = array_sum(array_map(static fn (array $g): int => count($g['questions']), $survey['groups']));
$value = static fn (int $id): mixed => $answers[$id] ?? $answers[(string) $id] ?? null;
$disabled = $answerable ? '' : ' disabled';
?>
<div class="page-head">
    <h1><?= e($survey['title']) ?></h1>
    <a class="btn small" href="<?= e(url('/support')) ?>">← <?= e(__('ui.nav.support')) ?></a>
</div>
<?php if ($survey['description']): ?><p class="support-lead"><?= nl2br(e($survey['description'])) ?></p><?php endif; ?>

<?php if (!$answerable): ?>
    <p class="notice"><?= e(__($answered ? 'ui.support.survey_closed_answered' : 'ui.support.survey_closed_text')) ?></p>
<?php elseif ($answered): ?>
    <p class="muted"><?= e(__('ui.support.survey_editing')) ?></p>
<?php endif; ?>

<form method="post" action="<?= e(url('/support/survey')) ?>" class="survey-form" data-survey-form data-total="<?= $total ?>" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $survey['id'] ?>">

    <?php if ($answerable): ?>
        <div class="survey-progress" aria-hidden="true"><span data-survey-bar></span></div>
    <?php endif; ?>

    <?php $n = 0; foreach ($survey['groups'] as $group): ?>
        <section class="card survey-group">
            <?php if ($group['title'] !== ''): ?><h2><?= e($group['title']) ?></h2><?php endif; ?>
            <?php if ($group['description'] !== ''): ?><p class="muted"><?= nl2br(e($group['description'])) ?></p><?php endif; ?>

            <?php foreach ($group['questions'] as $q): $n++;
                $id = (int) $q['id']; $name = 'q[' . $id . ']'; $current = $value($id); $error = $errors[$id] ?? $errors[(string) $id] ?? null;
                $choices = (array) ($q['options']['choices'] ?? []); ?>
                <fieldset class="survey-question<?= $error ? ' has-error' : '' ?>" data-question data-required="<?= $q['required'] ? '1' : '0' ?>" id="q-<?= $id ?>">
                    <legend><span class="survey-num"><?= $n ?></span> <?= e($q['label']) ?><?php if ($q['required']): ?> <span class="survey-required" title="<?= e(__('ui.support.required')) ?>">*</span><?php endif; ?></legend>
                    <?php if ($q['help'] !== ''): ?><p class="muted small"><?= e($q['help']) ?></p><?php endif; ?>

                    <?php switch ($q['type']):
                        case 'short_text': ?>
                            <input type="text" name="<?= e($name) ?>" maxlength="300" value="<?= e(is_scalar($current) ? (string) $current : '') ?>"<?= $disabled ?>>
                        <?php break; case 'long_text': ?>
                            <textarea name="<?= e($name) ?>" rows="4" maxlength="5000"<?= $disabled ?>><?= e(is_scalar($current) ? (string) $current : '') ?></textarea>
                        <?php break; case 'radio': ?>
                            <div class="survey-choices">
                                <?php foreach ($choices as $choice): ?>
                                    <label class="survey-choice"><input type="radio" name="<?= e($name) ?>" value="<?= e($choice['key']) ?>" <?= (string) $current === $choice['key'] ? 'checked' : '' ?><?= $disabled ?>> <span><?= e($choice['label']) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        <?php break; case 'checkbox': ?>
                            <div class="survey-choices">
                                <?php foreach ($choices as $choice): ?>
                                    <label class="survey-choice"><input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($choice['key']) ?>" <?= in_array($choice['key'], array_map('strval', (array) $current), true) ? 'checked' : '' ?><?= $disabled ?>> <span><?= e($choice['label']) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        <?php break; case 'select': ?>
                            <select name="<?= e($name) ?>"<?= $disabled ?>>
                                <option value=""><?= e(__('ui.support.choose')) ?></option>
                                <?php foreach ($choices as $choice): ?>
                                    <option value="<?= e($choice['key']) ?>" <?= (string) $current === $choice['key'] ? 'selected' : '' ?>><?= e($choice['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php break; case 'scale':
                            $min = (int) ($q['options']['min'] ?? 1); $max = (int) ($q['options']['max'] ?? 5); ?>
                            <div class="survey-scale">
                                <?php if (($q['options']['min_label'] ?? '') !== ''): ?><span class="survey-scale-label"><?= e($q['options']['min_label']) ?></span><?php endif; ?>
                                <span class="survey-scale-points">
                                    <?php for ($i = $min; $i <= $max; $i++): ?>
                                        <label><input type="radio" name="<?= e($name) ?>" value="<?= $i ?>" <?= (string) $current === (string) $i ? 'checked' : '' ?><?= $disabled ?>><span><?= $i ?></span></label>
                                    <?php endfor; ?>
                                </span>
                                <?php if (($q['options']['max_label'] ?? '') !== ''): ?><span class="survey-scale-label"><?= e($q['options']['max_label']) ?></span><?php endif; ?>
                            </div>
                        <?php break; case 'yes_no': ?>
                            <div class="survey-choices inline">
                                <?php foreach (['yes', 'no'] as $choice): ?>
                                    <label class="survey-choice"><input type="radio" name="<?= e($name) ?>" value="<?= $choice ?>" <?= $current === $choice ? 'checked' : '' ?><?= $disabled ?>> <span><?= e(__('ui.support.' . $choice)) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        <?php break; case 'number': ?>
                            <input type="number" name="<?= e($name) ?>" step="any" value="<?= e(is_scalar($current) ? (string) $current : '') ?>" class="survey-number"<?= $disabled ?>>
                        <?php break; case 'date': ?>
                            <input type="date" name="<?= e($name) ?>" value="<?= e(is_scalar($current) ? (string) $current : '') ?>" class="survey-date"<?= $disabled ?>>
                    <?php endswitch; ?>

                    <?php if ($error): ?><p class="survey-error"><?= e($error) ?></p><?php endif; ?>
                </fieldset>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <?php if ($answerable): ?>
        <div class="survey-submit">
            <span class="muted small" data-survey-count data-template="<?= e(__('ui.support.survey_answered_n')) ?>"></span>
            <button type="submit" class="btn primary"><?= e(__($answered ? 'ui.support.survey_update' : 'ui.support.survey_send')) ?></button>
        </div>
    <?php endif; ?>
</form>
