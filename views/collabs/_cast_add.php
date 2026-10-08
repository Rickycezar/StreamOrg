<?php
/**
 * "Add a streamer" inside a collab form: a Twitch search that adds the
 * person to the streamers list and ticks them in this collab, without
 * leaving the form (see app.js, [data-cast-add]). Without Twitch set up,
 * it links to the Streamers page instead.
 *
 * @var bool $twitchReady
 */
?>
<div class="cast-add" data-cast-add>
    <?php if ($twitchReady): ?>
        <button type="button" class="btn small" data-cast-add-toggle aria-expanded="false">+ <?= e(__('ui.action.add_streamer')) ?></button>
        <div class="cast-add-panel" data-cast-add-panel hidden>
            <input type="search" data-cast-add-search autocomplete="off" placeholder="<?= e(__('ui.label.cast_add_hint')) ?>" aria-label="<?= e(__('ui.action.add_streamer')) ?>">
            <div class="cast-add-results" data-cast-add-results
                 data-added="<?= e(__('ui.label.cast_added')) ?>" data-empty="<?= e(__('ui.label.picker_none')) ?>"
                 data-streamorg="<?= e(__('ui.label.on_streamorg')) ?>" data-streamorg-hint="<?= e(__('ui.label.on_streamorg_hint')) ?>"></div>
        </div>
    <?php else: ?>
        <a class="btn small" href="<?= e(url('/streamers')) ?>">+ <?= e(__('ui.action.add_streamer')) ?></a>
    <?php endif; ?>
</div>
