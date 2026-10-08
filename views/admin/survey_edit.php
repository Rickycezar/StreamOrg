<?php
/**
 * Administration → the questionnaire builder: its settings (title,
 * description, who answers, when it closes) and its sections with their
 * questions — the kind of answer, the question, a hint, required or not,
 * choices or a scale — added, moved, copied and removed in place (drawn by
 * app.js from the JSON below, [data-survey-builder]). Saved as a whole.
 * Every text is written per language: the bar on top switches the
 * language being written and counts what is still untranslated; a missing
 * translation shows the other language as a hint.
 *
 * @var array $survey with groups and users @var int $responses answers already given
 */
$types = array_combine(Surveys::TYPES, array_map(static fn (string $t): string => __('ui.survey_type.' . $t), Surveys::TYPES));
$closes = $survey['closes_at'] ? (new DateTimeImmutable((string) $survey['closes_at']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i') : '';
$data = [
    'id'          => $survey['id'],
    'title'       => (object) $survey['title'],
    'description' => (object) $survey['description'],
    'audience'    => $survey['audience'],
    'closes_at'   => $closes,
    'users'       => array_map(static fn (array $u): array => ['id' => (int) $u['id'], 'name' => $u['name'], 'username' => $u['username']], $survey['users']),
    'groups'      => $survey['groups'],
];
$strings = [
    'locales'        => array_combine(Lang::available(), array_map(static fn (string $l): string => Lang::t('ui.label.language_name', $l), Lang::available())),
    'locale'         => Lang::locale(),
    'missing'        => __('ui.support.lang_missing'),
    'complete'       => __('ui.support.lang_complete'),
    'writing_in'     => __('ui.support.lang_writing'),
    'types'          => $types,
    'section'        => __('ui.support.b_section'),
    'section_title'  => __('ui.support.b_section_title'),
    'section_desc'   => __('ui.support.b_section_desc'),
    'question'       => __('ui.support.b_question'),
    'question_label' => __('ui.support.b_question_label'),
    'help'           => __('ui.support.b_help'),
    'required'       => __('ui.support.required'),
    'choices'        => __('ui.support.b_choices'),
    'choice'         => __('ui.support.b_choice'),
    'add_choice'     => __('ui.support.b_add_choice'),
    'add_question'   => __('ui.support.b_add_question'),
    'add_section'    => __('ui.support.b_add_section'),
    'scale_from'     => __('ui.support.b_scale_from'),
    'scale_to'       => __('ui.support.b_scale_to'),
    'scale_min_label'=> __('ui.support.b_scale_min_label'),
    'scale_max_label'=> __('ui.support.b_scale_max_label'),
    'move_up'        => __('ui.action.move_up'),
    'move_down'      => __('ui.action.move_down'),
    'duplicate'      => __('ui.support.b_duplicate'),
    'remove'         => __('ui.action.delete'),
    'remove_section' => __('ui.support.b_remove_section'),
    'remove_section_confirm' => __('ui.support.b_remove_section_confirm'),
    'type'           => __('ui.support.b_type'),
    'saving'         => __('ui.support.b_saving'),
    'unsaved'        => __('ui.support.b_unsaved'),
    'empty_section'  => __('ui.support.b_empty_section'),
];
?>
<div class="page-head">
    <h1><?= e($survey['id'] ? Surveys::text($survey['title']) : __('ui.support.survey_new')) ?>
        <span class="badge survey-<?= e($survey['status']) ?>"><?= e(__('ui.survey_status.' . $survey['status'])) ?></span></h1>
    <a class="btn small" href="<?= e(url('/admin/surveys')) ?>">← <?= e(__('ui.nav.surveys')) ?></a>
</div>

<?php if ($responses > 0): ?>
    <p class="notice"><?= e(sprintf(__('ui.support.b_has_answers'), $responses)) ?></p>
<?php endif; ?>

<div class="survey-builder" data-survey-builder>
    <script type="application/json" data-survey-data><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <script type="application/json" data-survey-strings><?= json_encode($strings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

    <div class="survey-langbar" data-survey-langs role="tablist" aria-label="<?= e(__('ui.support.lang_writing')) ?>"></div>
    <p class="muted small survey-lang-hint"><?= e(__('ui.support.lang_hint')) ?></p>

    <section class="card survey-settings">
        <h2><?= e(__('ui.support.b_settings')) ?></h2>
        <label>
            <span><?= e(__('ui.support.f_title')) ?></span>
            <input type="text" maxlength="140" data-field="title" data-i18n placeholder="<?= e(__('ui.support.b_title_hint')) ?>">
        </label>
        <label>
            <span><?= e(__('ui.support.b_description')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
            <textarea rows="2" maxlength="2000" data-field="description" data-i18n placeholder="<?= e(__('ui.support.b_description_hint')) ?>"></textarea>
        </label>
        <div class="grid">
            <fieldset class="survey-audience">
                <legend><?= e(__('ui.support.b_audience')) ?></legend>
                <label class="inline"><input type="radio" name="audience" value="everyone" data-field="audience"> <span><?= e(__('ui.support.audience_everyone')) ?></span></label>
                <label class="inline"><input type="radio" name="audience" value="users" data-field="audience"> <span><?= e(__('ui.support.audience_users')) ?></span></label>
                <div class="survey-users" data-survey-users hidden>
                    <select multiple data-picker="users" data-survey-picker data-placeholder="<?= e(__('ui.label.picker_search')) ?>"></select>
                </div>
            </fieldset>
            <label>
                <span><?= e(__('ui.support.b_closes')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                <input type="datetime-local" data-field="closes_at">
                <small class="muted"><?= e(__('ui.support.b_closes_hint')) ?></small>
            </label>
        </div>
    </section>

    <div class="survey-sections" data-survey-sections></div>
    <button type="button" class="btn" data-add-section>+ <?= e(__('ui.support.b_add_section')) ?></button>

    <div class="survey-savebar">
        <span class="muted small" data-survey-state></span>
        <p class="survey-save-error" data-survey-error hidden></p>
        <button type="button" class="btn" data-survey-save><?= e(__('ui.action.save')) ?></button>
        <?php if ($survey['status'] !== 'open'): ?>
            <button type="button" class="btn primary" data-survey-save data-status="open"><?= e(__('ui.support.b_save_open')) ?></button>
        <?php endif; ?>
    </div>
</div>
