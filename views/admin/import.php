<?php /** @var array<string,Provider> $providers @var array<string,Provider> $all */ ?>
<h1><?= e(__('ui.nav.import')) ?></h1>

<?php if ($providers === []): ?>
    <section class="card">
        <p class="empty"><?= e(__('ui.message.no_provider_configured')) ?></p>
        <p><a class="btn" href="<?= e(url('/admin/api')) ?>"><?= e(__('ui.nav.api_settings')) ?></a></p>
    </section>
<?php else: ?>
    <p class="muted"><?= e(__('ui.message.import_intro')) ?></p>

    <section class="card">
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.provider')) ?></span>
                <select id="import-provider">
                    <?php foreach ($providers as $code => $provider): ?>
                        <option value="<?= e($code) ?>"><?= e(code_label('api_provider', $code)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="grow">
                <span><?= e(__('ui.action.search')) ?></span>
                <input type="search" id="import-search" placeholder="<?= e(__('ui.label.search_hint')) ?>" autocomplete="off">
            </label>
        </div>

        <?php if (count($providers) < count($all)): ?>
            <p class="muted small">
                <?= e(sprintf(__('ui.message.providers_partial'), count($providers), count($all))) ?>
                <a href="<?= e(url('/admin/api')) ?>"><?= e(__('ui.nav.api_settings')) ?></a>
            </p>
        <?php endif; ?>

        <div id="import-results" class="results"></div>
    </section>
<?php endif; ?>
