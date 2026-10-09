<?php
/**
 * Stream tools → Chat bot: a sub-menu (overview, commands, timed messages,
 * logs) and the chosen sub-tab, from views/bot/tabs/.
 *
 * @var string $section @var array $account @var array $sectionData
 */
?>
<nav class="bot-tabs" aria-label="<?= e(__('ui.nav.chat_bot')) ?>">
    <?php foreach (BotController::SECTIONS as $path => [$label, $view]): ?>
        <a href="<?= e(url($path)) ?>" class="<?= $section === $path ? 'active' : '' ?>"
           <?= $section === $path ? 'aria-current="page"' : '' ?>><?= e(__($label)) ?></a>
    <?php endforeach; ?>
</nav>

<?= View::partial('bot/tabs/' . BotController::SECTIONS[$section][1], ['account' => $account, 'bot' => (string) $account['twitch_login'], 'prefix' => (string) $account['command_prefix']] + $sectionData) ?>
