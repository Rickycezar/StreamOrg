<?php
/**
 * One choice of a list's filter bar. Several values can be picked unless
 * $multiple is false; none picked means any. The bar applies itself as
 * soon as a choice changes.
 *
 * @var string $name @var string $label @var array<string, string> $options value => label
 * @var list<string>|string $selected @var ?bool $multiple @var ?bool $any (single choice: offer "any")
 */
$multiple = $multiple ?? true;
$any      = $any ?? true;
$chosen   = array_map('strval', (array) $selected);
?>
<label class="filter-field">
    <span><?= e($label) ?></span>
    <select name="<?= e($name . ($multiple ? '[]' : '')) ?>" data-filter-select<?= $multiple ? ' multiple' : '' ?>
            data-placeholder="<?= e(__('ui.label.any')) ?>">
        <?php if (!$multiple && $any): ?>
            <option value=""><?= e(__('ui.label.any')) ?></option>
        <?php endif; ?>
        <?php foreach ($options as $value => $text): ?>
            <option value="<?= e((string) $value) ?>"<?= in_array((string) $value, $chosen, true) ? ' selected' : '' ?>><?= e($text) ?></option>
        <?php endforeach; ?>
    </select>
</label>
