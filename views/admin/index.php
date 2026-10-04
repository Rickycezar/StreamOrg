<?php /** @var array $counts @var array<string,Provider> $providers */ ?>
<h1><?= e(__('ui.nav.admin')) ?></h1>
<p class="muted"><?= e(__('ui.message.admin_intro')) ?></p>

<section class="cards">
    <a class="card link" href="<?= e(url('/admin/games')) ?>">
        <h2><?= e(__('ui.nav.games')) ?></h2>
        <p class="big"><?= (int) $counts['games'] ?></p>
        <p class="muted"><?= e(__('ui.label.manage_games')) ?></p>
    </a>
    <a class="card link" href="<?= e(url('/admin/publishers')) ?>">
        <h2><?= e(__('ui.nav.publishers')) ?></h2>
        <p class="big"><?= (int) $counts['publishers'] ?></p>
        <p class="muted"><?= e(__('ui.label.manage_publishers')) ?></p>
    </a>
    <a class="card link" href="<?= e(url('/admin/developers')) ?>">
        <h2><?= e(__('ui.nav.developers')) ?></h2>
        <p class="big"><?= (int) $counts['developers'] ?></p>
        <p class="muted"><?= e(__('ui.label.manage_developers')) ?></p>
    </a>
    <a class="card link" href="<?= e(url('/admin/key-sites')) ?>">
        <h2><?= e(__('ui.nav.key_sites')) ?></h2>
        <p class="big"><?= (int) $counts['key_sites'] ?></p>
        <p class="muted"><?= e(__('ui.label.manage_key_sites')) ?></p>
    </a>
    <a class="card link" href="<?= e(url('/admin/import')) ?>">
        <h2><?= e(__('ui.nav.import')) ?></h2>
        <p class="big"><?= count(array_filter($providers, fn ($p) => $p->isAvailable())) ?>/<?= count($providers) ?></p>
        <p class="muted"><?= e(__('ui.label.providers_available')) ?></p>
    </a>
    <a class="card link" href="<?= e(url('/admin/lang')) ?>">
        <h2><?= e(__('ui.nav.languages')) ?></h2>
        <p class="big">⌘</p>
        <p class="muted"><?= e(__('ui.label.manage_lang')) ?></p>
    </a>
    <a class="card link" href="<?= e(url('/admin/settings')) ?>">
        <h2><?= e(__('ui.nav.settings')) ?></h2>
        <p class="big">⏱</p>
        <p class="muted"><?= e(__('ui.label.manage_settings')) ?></p>
    </a>
    <a class="card link" href="<?= e(url('/admin/api')) ?>">
        <h2><?= e(__('ui.nav.api_settings')) ?></h2>
        <p class="big">⚙</p>
        <p class="muted"><?= e(__('ui.label.manage_api')) ?></p>
    </a>
</section>
