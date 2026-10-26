<?php
/**
 * Tabs that switch the page's panels in place (no reload). Each panel is
 * an element with data-tab-panel="<key>"; the chosen tab goes in the
 * address (?tab=…) so a reload or a shared link opens the same one.
 *
 * @var string $name the page's tab set @var array<string, string> $tabs key => label @var string $active
 */
?>
<nav class="page-tabs" role="tablist" data-tabs="<?= e($name) ?>">
    <?php foreach ($tabs as $key => $label): ?>
        <button type="button" role="tab" data-tab="<?= e($key) ?>" aria-selected="<?= $active === $key ? 'true' : 'false' ?>"
                class="<?= $active === $key ? 'active' : '' ?>"><?= e($label) ?></button>
    <?php endforeach; ?>
</nav>
