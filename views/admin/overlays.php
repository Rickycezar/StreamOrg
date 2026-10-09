<?php
/**
 * Administration → Overlays: the settings for everyone (overlays on or
 * off, which types streamers may add, how many each, upload limits, the
 * address overlay links use and an optional live connection), how many
 * overlays of each type exist and are open, and the shared media library.
 *
 * @var array<string,string> $config @var list<string> $types @var string $baseUrl
 * @var array<string,array> $counts @var array $library
 */
$off = array_filter(explode(',', $config['overlay.types_off']));
?>
<h1><?= e(__('ui.nav.overlays')) ?></h1>
<p class="muted"><?= e(__('ui.overlay.admin_intro')) ?></p>

<section class="card">
    <h2><?= e(__('ui.overlay.admin_settings')) ?></h2>

    <form method="post" action="<?= e(url('/admin/overlays')) ?>" class="subform">
        <?= Csrf::field() ?>
        <label class="inline">
            <input type="checkbox" name="enabled" value="1" <?= $config['overlay.enabled'] === '1' ? 'checked' : '' ?>>
            <span><?= e(__('ui.overlay.admin_enabled')) ?></span>
        </label>

        <label class="inline">
            <input type="checkbox" name="custom_css" value="1" <?= $config['overlay.custom_css'] === '1' ? 'checked' : '' ?>>
            <span><?= e(__('ui.overlay.admin_custom_css')) ?></span>
        </label>

        <fieldset class="overlay-roles">
            <legend><?= e(__('ui.overlay.admin_types')) ?></legend>
            <?php foreach ($types as $type):
                $count = $counts[$type] ?? ['total' => 0, 'open' => 0]; ?>
                <label class="inline">
                    <input type="checkbox" name="types_on[]" value="<?= e($type) ?>" <?= in_array($type, $off, true) ? '' : 'checked' ?>>
                    <span><?= e(__('ui.overlay_type.' . $type)) ?> <small class="muted"><?= e(sprintf(__('ui.overlay.admin_type_count'), (int) $count['total'], (int) $count['open'])) ?></small></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <div class="grid">
            <label>
                <span><?= e(__('ui.overlay.admin_max_per_user')) ?></span>
                <input type="number" name="max_per_user" min="1" max="200" value="<?= (int) $config['overlay.max_per_user'] ?>">
            </label>
            <label>
                <span><?= e(__('ui.overlay.admin_poll')) ?></span>
                <span class="input-suffix">
                    <input type="number" name="poll_seconds" min="2" max="60" value="<?= (int) $config['overlay.poll_seconds'] ?>">
                    <span class="muted"><?= e(__('ui.label.unit_seconds')) ?></span>
                </span>
            </label>
        </div>

        <div class="grid">
            <label>
                <span><?= e(__('ui.overlay.admin_sound_max')) ?></span>
                <span class="input-suffix"><input type="number" name="sound_max_mb" min="1" max="10" value="<?= (int) $config['media.sound_max_mb'] ?>"><span class="muted">MB</span></span>
            </label>
            <label>
                <span><?= e(__('ui.overlay.admin_image_max')) ?></span>
                <span class="input-suffix"><input type="number" name="image_max_mb" min="1" max="10" value="<?= (int) $config['media.image_max_mb'] ?>"><span class="muted">MB</span></span>
            </label>
            <label>
                <span><?= e(__('ui.overlay.admin_quota')) ?></span>
                <span class="input-suffix"><input type="number" name="quota_mb" min="1" max="2000" value="<?= (int) $config['media.quota_mb'] ?>"><span class="muted">MB</span></span>
            </label>
        </div>

        <label>
            <span><?= e(__('ui.overlay.admin_base_url')) ?></span>
            <input type="url" name="base_url" value="<?= e($config['overlay.base_url']) ?>" placeholder="https://overlay.streamorg.com">
            <small class="muted"><?= e(sprintf(__('ui.overlay.admin_base_url_hint'), $baseUrl)) ?></small>
        </label>
        <label>
            <span><?= e(__('ui.overlay.admin_live_url')) ?></span>
            <input type="text" name="live_url" value="<?= e($config['overlay.live_url']) ?>" placeholder="wss://streamorg.com/live">
            <small class="muted"><?= e(__('ui.overlay.admin_live_url_hint')) ?></small>
        </label>

        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>
</section>

<?= View::partial('overlays/_library', $library) ?>
