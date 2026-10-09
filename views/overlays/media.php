<?php
/** Stream tools → Media library: the streamer's sounds and images, and the shared ones.
 *  @var array $library */
?>
<p class="muted"><?= e(__('ui.overlay.media_intro')) ?></p>
<?= View::partial('overlays/_library', $library) ?>
