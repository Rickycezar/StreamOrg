<?php
/**
 * One overlay's settings: name, on or off, and its type's fields (built
 * from OverlayTypes) in simple mode, or in advanced mode the settings as
 * text (OverlayIni) with what it has wrong, and CSS of the owner's own;
 * beside a live preview that follows every change before it is saved. Test buttons fire the preview or the overlay open in
 * OBS; the link (with the size to give the browser source) can be copied
 * or replaced.
 *
 * @var array $overlay @var array $definition @var list<array> $media @var bool $typeOn @var string $sampleUser
 * @var string $text @var list<array> $warnings @var bool $cssAllowed @var ?string $channel @var ?string $channelId
 * @var string $botStatus whether the chat bot can send this overlay's replies: active, off (not in the channel), unavailable
 */
$settings = $overlay['settings'];
$sounds   = array_values(array_filter($media, static fn (array $m): bool => $m['kind'] === 'sound'));
$images   = array_values(array_filter($media, static fn (array $m): bool => $m['kind'] === 'image'));
[$width, $height] = $definition['size'];
?>
<div class="page-head">
    <h1><?= e($overlay['name']) ?> <span class="badge"><?= e(__('ui.overlay_type.' . $overlay['type'])) ?></span></h1>
    <div class="page-actions">
        <?php $helpPage = 'overlays'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
        <a class="btn small" href="<?= e(url('/overlays')) ?>">← <?= e(__('ui.nav.overlays')) ?></a>
    </div>
</div>

<?php if (!$typeOn): ?>
    <p class="notice"><?= e(__('ui.message.overlay_type_off')) ?></p>
<?php endif; ?>

<div class="overlay-editor" data-overlay-editor data-id="<?= $overlay['id'] ?>">
    <form method="post" action="<?= e(url('/overlays/update')) ?>" class="card overlay-form" data-overlay-form>
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $overlay['id'] ?>">

        <div class="grid">
            <label>
                <span><?= e(__('ui.field.name')) ?></span>
                <input type="text" name="name" value="<?= e($overlay['name']) ?>" maxlength="80" required>
            </label>
            <label class="inline overlay-enabled">
                <input type="checkbox" name="enabled" value="1" <?= $overlay['enabled'] ? 'checked' : '' ?>>
                <span><?= e(__('ui.overlay.enabled')) ?></span>
            </label>
        </div>

        <div class="mode-switch" role="group" aria-label="<?= e(__('ui.overlay.mode')) ?>">
            <button type="submit" name="switch_to" value="simple" class="btn small <?= $overlay['advanced'] ? '' : 'active' ?>" <?= $overlay['advanced'] ? '' : 'disabled' ?> formnovalidate><?= e(__('ui.overlay.mode_simple')) ?></button>
            <button type="submit" name="switch_to" value="advanced" class="btn small <?= $overlay['advanced'] ? 'active' : '' ?>" <?= $overlay['advanced'] ? 'disabled' : '' ?> formnovalidate><?= e(__('ui.overlay.mode_advanced')) ?></button>
        </div>

        <?php if ($overlay['advanced']): ?>
            <input type="hidden" name="advanced" value="1">
            <p class="muted small"><?= e(__('ui.overlay.advanced_intro')) ?></p>
            <label class="code-field">
                <span><?= e(__('ui.overlay.config_text')) ?></span>
                <textarea name="config_text" rows="28" spellcheck="false" autocomplete="off" autocapitalize="off" maxlength="<?= OverlayIni::MAX_LENGTH ?>" data-config-text><?= e($text) ?></textarea>
            </label>
            <div class="ini-warnings" data-ini-warnings <?= $warnings === [] ? 'hidden' : '' ?>>
                <strong><?= e(__('ui.overlay.warnings_title')) ?></strong>
                <ul>
                    <?php foreach ($warnings as $w): ?>
                        <li><?= e(($w['line'] > 0 ? sprintf(__('ui.overlay_ini.line'), $w['line']) . ': ' : '') . $w['message']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ($cssAllowed): ?>
                <label class="code-field">
                    <span><?= e(__('ui.overlay.custom_css')) ?></span>
                    <textarea name="custom_css" rows="12" spellcheck="false" autocomplete="off" autocapitalize="off" maxlength="<?= Overlays::MAX_CSS ?>" placeholder="<?= e(__('ui.overlay.custom_css_placeholder')) ?>" data-custom-css><?= e($overlay['custom_css']) ?></textarea>
                </label>
                <details class="css-reference">
                    <summary><?= e(__('ui.overlay.css_reference')) ?></summary>
                    <pre><?= e(__('ui.overlay_css.common') . "\n\n" . __('ui.overlay_css.' . $overlay['type'])) ?></pre>
                </details>
            <?php else: ?>
                <p class="muted small"><?= e(__('ui.overlay.custom_css_off')) ?></p>
            <?php endif; ?>
        <?php else:
            $hidden  = 0;
            $main    = '';
            $viewers = '';
            $count   = 0;
            $label   = '';
            foreach ($definition['fields'] as $name => $field) {
                if (!empty($field['advanced']) || ($field['kind'] === 'map' && empty($field['simple']))) {
                    $hidden += $field['kind'] === 'map' ? count((array) ($settings[$name] ?? [])) : 1;
                    continue;
                }

                $html = View::partial('overlays/_field', ['type' => $overlay['type'], 'name' => $name, 'field' => $field, 'value' => $settings[$name] ?? null, 'sounds' => $sounds, 'images' => $images, 'botStatus' => $botStatus]);

                if ($field['kind'] === 'map') {
                    $viewers .= $html;
                    $label   = $label ?: __('ui.overlay.rules_' . $overlay['type'] . '_' . $name);
                    $count   += count((array) ($settings[$name] ?? []));
                } else {
                    $main .= $html;
                }
            } ?>
            <?php if ($viewers !== ''): ?>
                <div class="form-tabs" role="tablist" data-form-tabs data-key="overlay-tab-<?= (int) $overlay['id'] ?>">
                    <button type="button" role="tab" class="active" aria-selected="true" data-form-tab="main"><?= e(__('ui.overlay.tab_settings')) ?></button>
                    <button type="button" role="tab" aria-selected="false" data-form-tab="viewers"><?= e($label) ?><?php if ($count > 0): ?> <span class="badge"><?= $count ?></span><?php endif; ?></button>
                </div>
            <?php endif; ?>
            <div data-form-panel="main" role="tabpanel">
                <?= $main ?>
                <p class="muted small"><?= e(sprintf(__('ui.overlay.more_in_advanced'), $hidden)) ?><?= trim($overlay['custom_css']) !== '' ? ' ' . e(__('ui.overlay.css_kept')) : '' ?></p>
            </div>
            <?php if ($viewers !== ''): ?>
                <div data-form-panel="viewers" role="tabpanel" hidden><?= $viewers ?></div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
            <span class="muted small" data-unsaved hidden><?= e(__('ui.overlay.unsaved')) ?></span>
        </div>
    </form>

    <aside class="overlay-side">
        <section class="card">
            <div class="card-head">
                <h2><?= e(__('ui.overlay.preview')) ?></h2>
            </div>
            <div class="overlay-preview" style="aspect-ratio: <?= (int) $width ?> / <?= (int) $height ?>" data-preview-box data-width="<?= (int) $width ?>" data-height="<?= (int) $height ?>">
                <iframe src="<?= e(url('/overlay/' . rawurlencode($overlay['type']))) ?>" title="<?= e(__('ui.overlay.preview')) ?>" allow="autoplay" data-preview width="<?= (int) $width ?>" height="<?= (int) $height ?>"></iframe>
            </div>
            <div class="overlay-tests">
                <?php foreach ($definition['tests'] as $test): ?>
                    <div class="overlay-test">
                        <span><?= e(__('ui.overlay_test.' . $test)) ?></span>
                        <button type="button" class="btn small" data-test-preview="<?= e($test) ?>"><?= e(__('ui.overlay.test_preview')) ?></button>
                        <button type="button" class="btn small" data-test-live="<?= e($test) ?>" <?= $overlay['enabled'] && $typeOn ? '' : 'disabled' ?>><?= e(__('ui.overlay.test_live')) ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="muted small" data-test-result hidden></p>
        </section>

        <section class="card">
            <h2><?= e(__('ui.overlay.link')) ?></h2>
            <?php if ($overlay['link'] !== null): ?>
                <div class="overlay-link">
                    <input type="password" readonly value="<?= e($overlay['link']) ?>" id="overlay-link" aria-label="<?= e(__('ui.overlay.link')) ?>" data-reveal-on-focus>
                    <button type="button" class="btn small" data-copy="#overlay-link"><?= e(__('ui.overlay.copy_link')) ?></button>
                </div>
            <?php endif; ?>
            <p class="muted small"><?= e(sprintf(__('ui.overlay.obs_hint'), $width, $height)) ?></p>
            <p class="muted small"><?= e(__('ui.overlay.link_secret')) ?></p>
            <form method="post" action="<?= e(url('/overlays/new-link')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= $overlay['id'] ?>">
                <button type="submit" class="btn small" data-confirm="<?= e(__('ui.overlay.new_link_confirm')) ?>"><?= e(__('ui.overlay.new_link')) ?></button>
            </form>
        </section>
    </aside>

    <script type="application/json" data-overlay-media><?= json_encode($media, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <script type="application/json" data-overlay-strings><?= json_encode([
        'whole'      => __('ui.overlay.whole_sound'),
        'sent'       => __('ui.overlay.test_sent'),
        'sample'     => $sampleUser,
        'test_label' => __('ui.overlay.test_tag'),
        'channel'    => $channel,
        'channel_id' => $channelId,
        'line'       => __('ui.overlay_ini.line'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</div>
