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
$fieldId = 'when-' . bin2hex(random_bytes(4));
?>
<div class="when-grid" data-when data-stream-id="<?= $streamId !== null ? (int) $streamId : '' ?>">
    <label class="when-start" for="<?= e($fieldId) ?>-start">
        <span><?= e(__('ui.field.scheduled')) ?><?php if ($isNew): ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small><?php endif; ?></span>
        <span class="input-with-action">
            <input type="datetime-local" id="<?= e($fieldId) ?>-start" name="<?= e($names['start']) ?>" value="<?= e($start) ?>" data-when-start<?= !empty($required) ? ' required' : '' ?>>
            <button type="button" class="joined-action" data-when-today title="<?= e(__('ui.label.today_slot_hint')) ?>"><?= e(__('ui.action.today')) ?></button>
        </span>
        <?php if ($isNew): ?><small class="muted"><?= e(__('ui.label.undated_ok')) ?></small><?php endif; ?>
    </label>
    <label class="when-length" for="<?= e($fieldId) ?>-minutes">
        <span><?= e(__('ui.field.duration')) ?></span>
        <span class="duration-field" role="group">
            <button type="button" class="dur-step" data-when-step="-60" title="<?= e(__('ui.label.minus_hour')) ?>" aria-label="<?= e(__('ui.label.minus_hour')) ?>">−60</button>
            <button type="button" class="dur-step" data-when-step="-30" title="<?= e(__('ui.label.minus_half_hour')) ?>" aria-label="<?= e(__('ui.label.minus_half_hour')) ?>">−30</button>
            <span class="dur-value">
                <input type="number" id="<?= e($fieldId) ?>-minutes" name="<?= e($names['minutes']) ?>" value="<?= (int) $minutes ?>" step="5"
                       min="<?= ContentDefaults::MIN_MINUTES ?>" max="<?= ContentDefaults::MAX_MINUTES ?>" data-when-minutes>
                <span class="dur-unit">min</span>
            </span>
            <button type="button" class="dur-step" data-when-step="30" title="<?= e(__('ui.label.plus_half_hour')) ?>" aria-label="<?= e(__('ui.label.plus_half_hour')) ?>">+30</button>
            <button type="button" class="dur-step" data-when-step="60" title="<?= e(__('ui.label.plus_hour')) ?>" aria-label="<?= e(__('ui.label.plus_hour')) ?>">+60</button>
        </span>
        <small class="muted dur-human" data-when-human></small>
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
