<?php
/**
 * The words box of a list's filter bar: results follow as you type.
 *
 * @var string $value @var ?string $placeholder @var ?string $name
 */
?>
<label class="filter-search">
    <span><?= e(__('ui.action.search')) ?></span>
    <span class="filter-search-box">
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" name="<?= e($name ?? 'q') ?>" value="<?= e($value) ?>" autocomplete="off" data-filter-search
               placeholder="<?= e($placeholder ?? __('ui.label.search_list')) ?>">
    </span>
</label>
