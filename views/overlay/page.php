<?php
/**
 * An overlay's page, as OBS loads it: transparent, with the shared sound
 * library, the overlay runtime and the type's own script and style.
 * ComfyJS (bundled at a fixed version) comes along for types that read
 * chat. Everything else — settings, media, tests — the runtime fetches
 * with the key in the link's "#". The owner's own CSS (advanced mode)
 * goes into the empty style element, after the type's style.
 *
 * @var string $type @var array $definition
 */
$asset = static fn (string $path): string => url($path) . '?v=' . (@filemtime(dirname(__DIR__, 2) . '/public' . $path) ?: 0);
?>
<!DOCTYPE html>
<html lang="<?= e(Lang::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>StreamOrg overlay</title>
    <link rel="stylesheet" href="<?= e($asset('/assets/overlay.css')) ?>">
    <link rel="stylesheet" href="<?= e($asset('/assets/overlays/' . $type . '.css')) ?>">
    <style id="overlay-custom-css"></style>
</head>
<body class="overlay overlay-<?= e($type) ?>" data-type="<?= e($type) ?>" data-state-url="<?= e(url('/overlay/state')) ?>" data-lookup-url="<?= e(url('/overlay/lookup')) ?>"
      data-emotes="<?= !empty($definition['emotes']) ? '1' : '0' ?>"
      data-missing-key="<?= e(__('ui.overlay.missing_key')) ?>" data-unknown-key="<?= e(__('ui.overlay.unknown_key')) ?>">
    <div class="overlay-stage" id="overlay-stage"></div>
    <div class="overlay-notice" id="overlay-notice" hidden></div>
    <script src="<?= e($asset('/assets/sound.js')) ?>"></script>
    <?php if (!empty($definition['chat'])): ?><script src="<?= e($asset('/assets/vendor/comfy.min.js')) ?>"></script><?php endif; ?>
    <script src="<?= e($asset('/assets/overlay.js')) ?>"></script>
    <script src="<?= e($asset('/assets/overlays/' . $type . '.js')) ?>"></script>
</body>
</html>
