<?php
/**
 * @var array $user @var array<int, array{start: string, end: ?string}> $schedule
 * @var int $contentMinutes @var list<array{prefix:string, is_default:bool}> $prefixes
 */
$prefixRow = static function (string $key, string $text, bool $default): string {
    return '<div class="prefix-row">'
        . '<input type="text" name="prefix[' . e($key) . '][text]" value="' . e($text) . '" maxlength="' . ContentDefaults::PREFIX_MAX . '" placeholder="[STEAM DECK]" aria-label="' . e(__('ui.field.title_prefix')) . '">'
        . '<label class="prefix-default"><input type="radio" name="default_prefix" value="' . e($key) . '"' . ($default ? ' checked' : '') . '> ' . e(__('ui.label.default')) . '</label>'
        . '<button type="button" class="btn small" data-prefix-remove aria-label="' . e(__('ui.action.delete')) . '">×</button>'
        . '</div>';
};
?>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.stream_schedule')) ?></h2>
        <span class="muted small"><?= e(sprintf(__('ui.label.n_stream_days'), count($schedule))) ?></span>
    </div>
    <p class="muted small"><?= e(__('ui.message.stream_schedule_explain')) ?></p>

    <form method="post" action="<?= e(url('/profile/defaults')) ?>" class="subform" data-schedule-form>
        <?= Csrf::field() ?>
        <div class="schedule">
            <div class="schedule-head" aria-hidden="true">
                <span></span>
                <span><?= e(__('ui.field.starts_at')) ?></span>
                <span><?= e(__('ui.field.ends_at')) ?></span>
            </div>
            <?php for ($day = 1; $day <= 7; $day++):
                $on = isset($schedule[$day]); ?>
                <div class="schedule-day<?= $on ? ' on' : '' ?>">
                    <label class="schedule-toggle">
                        <input type="checkbox" name="day[<?= $day ?>][on]" value="1" <?= $on ? 'checked' : '' ?>>
                        <span><?= e(__('ui.weekday.' . $day)) ?></span>
                    </label>
                    <input type="time" name="day[<?= $day ?>][start]"
                           value="<?= e($schedule[$day]['start'] ?? StreamSchedule::FALLBACK_START) ?>"
                           aria-label="<?= e(__('ui.weekday.' . $day) . ' · ' . __('ui.field.starts_at')) ?>">
                    <input type="time" name="day[<?= $day ?>][end]"
                           value="<?= e($schedule[$day]['end'] ?? '') ?>"
                           aria-label="<?= e(__('ui.weekday.' . $day) . ' · ' . __('ui.field.ends_at')) ?>">
                </div>
            <?php endfor; ?>
        </div>
        <p class="muted small"><?= e(__('ui.message.stream_schedule_end_hint')) ?></p>

        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.content_defaults')) ?></h2>
    </div>

    <form method="post" action="<?= e(url('/profile/defaults/content')) ?>" class="subform" data-prefix-form>
        <?= Csrf::field() ?>
        <label class="narrow">
            <span><?= e(__('ui.field.content_length')) ?></span>
            <span class="input-suffix">
                <input type="number" name="content_minutes" required step="5"
                       min="<?= ContentDefaults::MIN_MINUTES ?>" max="<?= ContentDefaults::MAX_MINUTES ?>"
                       value="<?= $contentMinutes ?>">
                <span class="muted"><?= e(__('ui.label.unit_minutes')) ?></span>
            </span>
            <small class="muted"><?= e(__('ui.message.content_length_hint')) ?></small>
        </label>

        <fieldset class="inset">
            <legend><?= e(__('ui.field.title_prefixes')) ?></legend>
            <p class="muted small"><?= e(__('ui.message.title_prefixes_hint')) ?></p>
            <div class="prefix-rows" data-prefix-rows>
                <?php foreach ($prefixes as $i => $p): ?>
                    <?= $prefixRow((string) $i, $p['prefix'], $p['is_default']) ?>
                <?php endforeach; ?>
                <?= $prefixRow((string) count($prefixes), '', false) ?>
            </div>
            <template data-prefix-template><?= $prefixRow('__KEY__', '', false) ?></template>
            <div class="inline-actions">
                <button type="button" class="btn small" data-prefix-add>+ <?= e(__('ui.action.add_prefix')) ?></button>
                <label class="prefix-default"><input type="radio" name="default_prefix" value="" <?= array_filter($prefixes, static fn ($p) => $p['is_default']) === [] ? 'checked' : '' ?>> <?= e(__('ui.label.no_default_prefix')) ?></label>
            </div>
        </fieldset>

        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>
</section>
