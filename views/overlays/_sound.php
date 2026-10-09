<?php
/**
 * A sound picker: a sound from the media library (the user's own, then the
 * shared ones), its part (sprite segment, shown when it has parts), volume
 * and a play button. The page's script fills the parts and plays it.
 *
 * @var string $input the setting's form name @var mixed $value {asset, segment, volume}
 * @var list<array> $sounds @var string $label
 */
$chosen = is_array($value) && isset($value['asset']) ? (int) $value['asset'] : null;
?>
<div class="overlay-media-pick kind-sound">
    <label>
        <span><?= e($label) ?></span>
        <select name="<?= e($input) ?>[asset]" data-sound-asset>
            <option value=""><?= e(__('ui.overlay.no_sound')) ?></option>
            <?php foreach ([0 => 'ui.overlay.mine', 1 => 'ui.overlay.shared'] as $shared => $group):
                $items = array_filter($sounds, static fn (array $m): bool => $m['shared'] === (bool) $shared);
                if ($items === []) continue; ?>
                <optgroup label="<?= e(__($group)) ?>">
                    <?php foreach ($items as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= $chosen === $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
    </label>
    <label data-sound-segment-wrap>
        <span><?= e(__('ui.overlay.segment')) ?></span>
        <select name="<?= e($input) ?>[segment]" data-sound-segment data-chosen="<?= e((string) ($value['segment'] ?? '')) ?>"></select>
    </label>
    <label>
        <span><?= e(__('ui.overlay.volume')) ?></span>
        <input type="range" name="<?= e($input) ?>[volume]" min="0" max="1" step="0.05" value="<?= e((string) ($value['volume'] ?? 1)) ?>">
    </label>
    <button type="button" class="btn small" data-sound-play><?= e(__('ui.overlay.play')) ?></button>
</div>
