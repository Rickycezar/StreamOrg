<?php
/**
 * Screenshots for a bug report or a reply: drop them in, paste them
 * (Ctrl+V anywhere on the page) or choose files. Each is uploaded right
 * away; a click on one opens it large to point at the problem with
 * numbered pins and a note for each. The form gets images[] and
 * image_pins[<id>] (see app.js, [data-shot-uploader]).
 *
 * @var bool $compact smaller, for reply boxes
 */
$compact ??= false;
?>
<div class="shot-uploader<?= $compact ? ' compact' : '' ?>" data-shot-uploader data-max="<?= SupportImages::MAX_IMAGES ?>"
     data-max-bytes="<?= SupportImages::MAX_BYTES ?>"
     data-too-many="<?= e(sprintf(__('ui.support.shots_too_many'), SupportImages::MAX_IMAGES)) ?>"
     data-uploading="<?= e(__('ui.support.shots_uploading')) ?>"
     data-pin-note="<?= e(__('ui.support.pin_note')) ?>"
     data-remove="<?= e(__('ui.action.delete')) ?>"
     data-point="<?= e(__('ui.support.shots_point')) ?>">
    <button type="button" class="shot-drop" data-shot-drop>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h3l2-3h6l2 3h3a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1zM12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg>
        <span><strong><?= e(__('ui.support.shots_add')) ?></strong>
            <small><?= e(__('ui.support.shots_how')) ?></small></span>
    </button>
    <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden data-shot-file>
    <div class="shot-thumbs" data-shot-thumbs></div>
    <div class="shot-board" data-shot-board hidden>
        <p class="shot-board-hint"><?= e(__('ui.support.pins_hint')) ?></p>
        <div class="shot-stage" data-shot-stage></div>
        <ol class="shot-pins" data-shot-pins></ol>
        <button type="button" class="btn small" data-shot-close><?= e(__('ui.support.pins_done')) ?></button>
    </div>
    <p class="shot-error" data-shot-error hidden></p>
</div>
