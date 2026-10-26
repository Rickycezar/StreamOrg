<?php
/**
 * The end of a list's filter bar: the button for browsers without
 * scripts, and Clear when something is filtered.
 *
 * @var string $clear the list's address without filters @var bool $active
 */
?>
<div class="filter-actions">
    <button type="submit" class="btn filter-submit"><?= e(__('ui.action.filter')) ?></button>
    <?php if ($active): ?>
        <a class="btn ghost filter-clear" href="<?= e(url($clear)) ?>"><?= icon_label('x', __('ui.action.clear')) ?></a>
    <?php endif; ?>
</div>
