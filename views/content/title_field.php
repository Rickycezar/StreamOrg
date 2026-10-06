<?php
/**
 * The content title as two parts that read as one field: the prefixes
 * (chips, numbered by the calendar) and the creator's own text. Below,
 * the finished title in colours (prefix, automatic number, own text,
 * added tags) with its length against Twitch's limit. Used by the new
 * content form and every edit form.
 *
 * @var list<array{id:int, prefix:string, is_default:bool}> $prefixes the user's prefixes
 * @var list<string> $chosen templates already on the content (or the defaults, for a new one)
 * @var string $body the creator's own text
 * @var ?int $streamId the content being edited, null when new
 */
?>
<div class="title-field" data-title-field data-stream-id="<?= $streamId !== null ? (int) $streamId : '' ?>">
    <span class="title-field-label"><?= e(__('ui.field.title')) ?></span>
    <input type="hidden" name="prefixes" value="<?= e((string) json_encode(array_values($chosen))) ?>" data-prefix-input>
    <div class="title-compose">
        <span class="prefix-chosen" data-prefix-chosen></span>
        <textarea name="title" rows="2" required class="title-area title-body" aria-label="<?= e(__('ui.field.title')) ?>"
                  placeholder="<?= e(__('ui.label.title_body_hint')) ?>"><?= e($body) ?></textarea>
    </div>
    <?php if ($prefixes !== []): ?>
        <div class="prefix-options" data-prefix-options>
            <?php foreach ($prefixes as $p): ?>
                <button type="button" class="prefix-option" data-prefix-text="<?= e($p['prefix']) ?>">+ <?= e($p['prefix']) ?></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="title-preview" data-title-preview data-max="<?= ContentController::TWITCH_TITLE_MAX ?>"
         data-undated="<?= e(__('ui.label.number_when_dated')) ?>" data-numbered="<?= e(__('ui.label.number_by_calendar')) ?>"
         data-over="<?= e(__('ui.label.twitch_title_over')) ?>"></div>
    <div class="title-legend" aria-hidden="true">
        <span class="seg-prefix"><?= e(__('ui.label.legend_prefix')) ?></span>
        <span class="seg-number"><?= e(__('ui.label.legend_number')) ?></span>
        <span class="seg-text"><?= e(__('ui.label.legend_text')) ?></span>
        <span class="seg-tag"><?= e(__('ui.label.legend_tags')) ?></span>
    </div>
</div>
