<?php
/** Stream tools (from the account menu): heading, the tabs (overlays, media library, chat bot), then the tab.
 *  @var string $tab @var string $tabView @var array $tabData */
$tabs = ['/overlays' => 'ui.overlay.tab_overlays', '/overlays/media' => 'ui.nav.media_library']
      + (ChatBot::isAvailable() ? ['/bot' => 'ui.nav.chat_bot'] : []);
?>
<h1><?= e(__('ui.nav.stream_tools')) ?></h1>

<div class="profile-layout">
    <nav class="subnav" aria-label="<?= e(__('ui.nav.stream_tools')) ?>">
        <?php foreach ($tabs as $path => $label): ?>
            <a href="<?= e(url($path)) ?>" class="<?= $tab === $path ? 'active' : '' ?>"
               <?= $tab === $path ? 'aria-current="page"' : '' ?>><?= e(__($label)) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="profile-body">
        <?= View::partial($tabView, $tabData) ?>
    </div>
</div>
