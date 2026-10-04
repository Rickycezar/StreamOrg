<?php
/** Shared frame for every profile tab: heading, sub-menu, then the tab.
 *  The tab's view and data travel as $tabView / $tabData: View::capture()
 *  extracts with EXTR_SKIP, so names like $template or $data would be
 *  shadowed by its own parameters (and $template made this frame render
 *  itself forever).
 *  @var array $user @var string $tab @var string $tabView @var array $tabData */
?>
<h1><?= e(__('ui.nav.profile')) ?></h1>

<div class="profile-layout">
    <nav class="subnav" aria-label="<?= e(__('ui.nav.profile')) ?>">
        <?php foreach (ProfileController::TABS as $path => $label): ?>
            <a href="<?= e(url($path)) ?>" class="<?= $tab === $path ? 'active' : '' ?>"
               <?= $tab === $path ? 'aria-current="page"' : '' ?>><?= e(__($label)) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="profile-body">
        <?= View::partial($tabView, ['user' => $user] + $tabData) ?>
    </div>
</div>
