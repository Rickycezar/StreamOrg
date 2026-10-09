<?php
/**
 * A media library: drop or pick files to upload (sounds are measured
 * before they go), then each sound with a player and its segments (a
 * sprite: name, start and end, set while listening), each image with a
 * thumbnail, and rename and delete. A user's library also lists the shared
 * media, read-only; the administration's lists only the shared media.
 *
 * @var list<array> $assets @var bool $shared @var ?int $usage @var int $quota @var int $maxSound @var int $maxImage
 */
$mb = static fn (int $bytes): string => number_format($bytes / 1048576, 1);
$strings = [
    'save'           => __('ui.action.save'),
    'saved'          => __('ui.message.saved'),
    'delete'         => __('ui.action.delete'),
    'delete_confirm' => __('ui.overlay.media_delete_confirm'),
    'play'           => __('ui.overlay.play'),
    'stop'           => __('ui.overlay.stop'),
    'segments'       => __('ui.overlay.segments'),
    'segments_hint'  => __('ui.overlay.segments_hint'),
    'add_segment'    => __('ui.overlay.add_segment'),
    'segment_name'   => __('ui.overlay.segment_name'),
    'start'          => __('ui.overlay.segment_start'),
    'end'            => __('ui.overlay.segment_end'),
    'mark'           => __('ui.overlay.mark_now'),
    'mark_short'     => __('ui.overlay.mark_short'),
    'remove'         => __('ui.action.remove'),
    'shared'         => __('ui.overlay.shared'),
    'uploading'      => __('ui.overlay.uploading'),
    'usage'          => __('ui.overlay.usage'),
    'whole'          => __('ui.overlay.whole_sound'),
    'name'           => __('ui.field.name'),
];
?>
<section class="card media-library" data-media-library data-shared="<?= $shared ? '1' : '0' ?>">
    <div class="card-head">
        <h2><?= e(__($shared ? 'ui.overlay.shared_media' : 'ui.nav.media_library')) ?></h2>
        <?php if ($usage !== null): ?>
            <span class="muted small" data-media-usage data-quota="<?= $quota ?>"><?= e(sprintf(__('ui.overlay.usage'), $mb($usage), $mb($quota))) ?></span>
        <?php endif; ?>
    </div>

    <div class="media-drop" data-media-drop tabindex="0" role="button">
        <strong><?= e(__('ui.overlay.media_drop')) ?></strong>
        <small class="muted"><?= e(sprintf(__('ui.overlay.media_kinds'), $mb($maxSound), $mb($maxImage))) ?></small>
        <input type="file" accept="audio/mpeg,audio/ogg,audio/wav,audio/x-wav,.mp3,.ogg,.wav,image/png,image/jpeg,image/gif,image/webp" multiple hidden data-media-input>
    </div>
    <p class="media-error" data-media-error hidden></p>

    <div class="media-grid" data-media-list></div>
    <p class="empty" data-media-empty hidden><?= e(__('ui.overlay.media_none')) ?></p>

    <script type="application/json" data-media-assets><?= json_encode($assets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <script type="application/json" data-media-strings><?= json_encode($strings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</section>
