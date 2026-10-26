<?php
/**
 * When content happens: its start (with "Today", the first free spot of
 * today's schedule), how long it runs and when that ends, kept in step
 * with each other, and its deadline. Used by the new content form and
 * every edit form.
 *
 * @var string $start the start as Y-m-d\TH:i, or '' @var int $minutes its length @var ?string $deadline (null: no deadline field)
 * @var ?int $streamId the content being edited (left out of today's spots), null when new
 * @var ?bool $isNew @var ?bool $required @var ?array{start:string, minutes:string} $names field names (the plan-together form uses its own)
 */
$isNew = $isNew ?? false;
$names = ($names ?? []) + ['start' => 'scheduled_start', 'minutes' => 'planned_minutes'];
?>
<div class="when-grid" data-when data-stream-id="<?= $streamId !== null ? (int) $streamId : '' ?>">
    <label class="when-start">
        <span><?= e(__('ui.field.scheduled')) ?><?php if ($isNew): ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small><?php endif; ?></span>
        <span class="input-with-action">
            <input type="datetime-local" name="<?= e($names['start']) ?>" value="<?= e($start) ?>" data-when-start<?= !empty($required) ? ' required' : '' ?>>
            <button type="button" class="btn small" data-when-today title="<?= e(__('ui.label.today_slot_hint')) ?>"><?= e(__('ui.action.today')) ?></button>
        </span>
        <?php if ($isNew): ?><small class="muted"><?= e(__('ui.label.undated_ok')) ?></small><?php endif; ?>
    </label>
    <label class="when-length">
        <span><?= e(__('ui.field.duration')) ?></span>
        <span class="duration-field">
            <button type="button" class="btn small" data-when-step="-60" aria-label="<?= e(__('ui.label.minus_hour')) ?>">−1h</button>
            <button type="button" class="btn small" data-when-step="-30" aria-label="<?= e(__('ui.label.minus_half_hour')) ?>">−30</button>
            <span class="input-suffix">
                <input type="number" name="<?= e($names['minutes']) ?>" value="<?= (int) $minutes ?>" step="5"
                       min="<?= ContentDefaults::MIN_MINUTES ?>" max="<?= ContentDefaults::MAX_MINUTES ?>" data-when-minutes>
                <span class="muted"><?= e(__('ui.label.unit_minutes')) ?></span>
            </span>
            <button type="button" class="btn small" data-when-step="30" aria-label="<?= e(__('ui.label.plus_half_hour')) ?>">+30</button>
            <button type="button" class="btn small" data-when-step="60" aria-label="<?= e(__('ui.label.plus_hour')) ?>">+1h</button>
        </span>
    </label>
    <label class="when-end">
        <span><?= e(__('ui.field.ends_at')) ?></span>
        <input type="datetime-local" data-when-end>
    </label>
    <?php if ($deadline !== null): ?>
        <label class="when-deadline">
            <span><?= e(__('ui.field.deadline')) ?></span>
            <input type="datetime-local" name="deadline" value="<?= e($deadline) ?>">
            <?php if ($isNew): ?><small class="muted"><?= e(__('ui.label.deadline_default')) ?></small><?php endif; ?>
        </label>
    <?php endif; ?>
</div>
