<?php
/**
 * @var array $user @var array<int, array{start: string, end: ?string}> $schedule
 * @var int $contentMinutes @var list<array{id:int, prefix:string, is_default:bool}> $prefixes
 * @var list<array{id:int, name:string, value:int}> $counters @var string $collabPrefix
 */
$remove = '<button type="button" class="btn small" data-row-remove aria-label="' . e(__('ui.action.delete')) . '">×</button>';

$prefixRow = static function (string $key, string $text, bool $default) use ($remove): string {
    return '<div class="prefix-row row-item">'
        . '<input type="text" name="prefix[' . e($key) . '][text]" value="' . e($text) . '" maxlength="' . ContentDefaults::PREFIX_MAX . '" placeholder="[STREAM #{stream}]" aria-label="' . e(__('ui.field.title_prefix')) . '">'
        . '<label class="prefix-default"><input type="checkbox" name="prefix[' . e($key) . '][default]" value="1"' . ($default ? ' checked' : '') . '> ' . e(__('ui.label.use_by_default')) . '</label>'
        . '<button type="button" class="btn small" data-row-up title="' . e(__('ui.action.move_up')) . '" aria-label="' . e(__('ui.action.move_up')) . '">↑</button>'
        . $remove
        . '</div>';
};

$counterRow = static function (string $key, ?array $counter) use ($remove): string {
    return '<div class="counter-row row-item">'
        . ($counter !== null ? '<input type="hidden" name="counter[' . e($key) . '][id]" value="' . (int) $counter['id'] . '">' : '')
        . '<span class="counter-name"><span>{</span><input type="text" name="counter[' . e($key) . '][name]" value="' . e($counter['name'] ?? '') . '" maxlength="20" pattern="[a-z][a-z0-9_]{0,19}" placeholder="stream" autocomplete="off" aria-label="' . e(__('ui.field.counter_name')) . '"><span>}</span></span>'
        . '<label class="counter-value"><span>' . e(__('ui.field.counter_value')) . '</span><input type="number" name="counter[' . e($key) . '][value]" value="' . (int) ($counter['value'] ?? 0) . '" min="0" max="' . TitleCounters::VALUE_MAX . '"></label>'
        . $remove
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
        <?php $helpPage = 'defaults'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
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

        <fieldset class="inset" data-rows-scope>
            <legend><?= e(__('ui.field.title_prefixes')) ?></legend>
            <p class="muted small"><?= e(__('ui.message.title_prefixes_hint')) ?></p>
            <div class="prefix-rows" data-rows="prefix">
                <?php foreach ($prefixes as $i => $p): ?>
                    <?= $prefixRow((string) $i, $p['prefix'], $p['is_default']) ?>
                <?php endforeach; ?>
                <?= $prefixRow((string) count($prefixes), '', false) ?>
            </div>
            <template data-row-template="prefix"><?= $prefixRow('__KEY__', '', false) ?></template>
            <button type="button" class="btn small" data-row-add="prefix">+ <?= e(__('ui.action.add_prefix')) ?></button>
        </fieldset>

        <label class="collab-prefix">
            <span><?= e(__('ui.field.collab_prefix')) ?></span>
            <span class="input-suffix">
                <input type="text" name="collab_prefix" value="<?= e($collabPrefix) ?>" maxlength="<?= ContentDefaults::COLLAB_PREFIX_MAX ?>" placeholder="ft." autocomplete="off">
                <span class="muted collab-prefix-example" data-collab-example>
                    <?= e(trim($collabPrefix . ' @hoku_xx, @eulink')) ?>
                </span>
            </span>
            <small class="muted"><?= e(__('ui.message.collab_prefix_hint')) ?></small>
        </label>

        <fieldset class="inset" data-rows-scope>
            <legend><?= e(__('ui.field.counters')) ?></legend>
            <p class="muted small"><?= e(__('ui.message.counters_hint')) ?></p>
            <div class="counter-rows" data-rows="counter">
                <?php foreach ($counters as $i => $c): ?>
                    <?= $counterRow((string) $i, $c) ?>
                <?php endforeach; ?>
                <?php if ($counters === []): ?><?= $counterRow('0', null) ?><?php endif; ?>
            </div>
            <template data-row-template="counter"><?= $counterRow('__KEY__', null) ?></template>
            <button type="button" class="btn small" data-row-add="counter">+ <?= e(__('ui.action.add_counter')) ?></button>
        </fieldset>

        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>
</section>
