<?php
/** @var array $user @var array<int, array{start: string, end: ?string}> $schedule */
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
