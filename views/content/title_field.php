<?php
/**
 * The content title as a small code editor: the text is typed freely
 * (prefixes included) and coloured as it is typed — prefixes, counters,
 * collab credits, developer/publisher tags and sponsor tags — by a layer
 * drawn under a transparent text box, so editing stays native. Under it:
 * buttons that add a prefix, what each counter will read, the length
 * against Twitch's limit, and what the colours mean. Used by the new
 * content form and every edit form.
 *
 * @var list<array{id:int, prefix:string, is_default:bool}> $prefixes the user's prefixes
 * @var string $value the title (or its template, with {counters})
 * @var ?int $streamId the content being edited, null when new
 */
?>
<div class="title-editor-wrap" data-title-editor data-stream-id="<?= $streamId !== null ? (int) $streamId : '' ?>">
    <span class="title-field-label"><?= e(__('ui.field.title')) ?></span>
    <div class="title-editor">
        <div class="title-highlight" aria-hidden="true" data-title-highlight></div>
        <textarea name="title" rows="1" required class="title-input" data-title-input spellcheck="false"
                  aria-label="<?= e(__('ui.field.title')) ?>" placeholder="<?= e(__('ui.label.title_body_hint')) ?>"><?= e($value) ?></textarea>
    </div>
    <div class="title-editor-foot">
        <?php if ($prefixes !== []): ?>
            <span class="title-prefix-buttons">
                <?php foreach ($prefixes as $p): ?>
                    <button type="button" class="prefix-option" data-prefix-text="<?= e($p['prefix']) ?>">+ <?= e($p['prefix']) ?></button>
                <?php endforeach; ?>
            </span>
        <?php endif; ?>
        <span class="title-hint" data-title-hint data-max="<?= ContentController::TWITCH_TITLE_MAX ?>"
              data-undated="<?= e(__('ui.label.number_when_dated')) ?>" data-over="<?= e(__('ui.label.twitch_title_over')) ?>"></span>
    </div>
    <div class="title-legend" aria-hidden="true">
        <span class="tok-prefix"><?= e(__('ui.label.legend_prefix')) ?></span>
        <span class="tok-collab"><?= e(__('ui.label.legend_collab')) ?></span>
        <span class="tok-devpub"><?= e(__('ui.label.legend_devpub')) ?></span>
        <span class="tok-sponsor"><?= e(__('ui.label.legend_sponsor')) ?></span>
    </div>
</div>
