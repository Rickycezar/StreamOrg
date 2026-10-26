<?php
/**
 * The parts of a title as coloured chips in their order, and what each
 * colour means. Dragged (or moved with the arrow keys), they change the
 * order: in the title editor the title is rewritten in the new order; in
 * Profile → Defaults the order is saved as the default for new titles.
 *
 * @var list<string> $order @var ?bool $field true to send the order with the form (title_order)
 */
$labels = [
    'prefix'  => __('ui.label.legend_prefix'),
    'text'    => __('ui.label.legend_text'),
    'suffix'  => __('ui.label.legend_suffix'),
    'collab'  => __('ui.label.legend_collab'),
    'game'    => __('ui.label.legend_game'),
    'devpub'  => __('ui.label.legend_devpub'),
    'sponsor' => __('ui.label.legend_sponsor'),
];
?>
<div class="title-order" data-title-order>
    <span class="title-order-hint muted"><?= e(__('ui.label.title_order_hint')) ?></span>
    <ol class="title-legend" role="listbox" aria-label="<?= e(__('ui.field.title_order')) ?>">
        <?php foreach ($order as $part): ?>
            <li class="tok-<?= e($part) ?>" data-part="<?= e($part) ?>" draggable="true" tabindex="0" role="option"><?= e($labels[$part] ?? $part) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php if (!empty($field)): ?>
        <input type="hidden" name="title_order" value="<?= e(implode(',', $order)) ?>" data-title-order-input>
    <?php endif; ?>
</div>
