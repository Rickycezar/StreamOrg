<?php
/** @var array $settings @var array $hints @var array<string,Provider> $providers
 *  @var bool $cryptoReady */

/**
 * Renders one integration's form. Secrets are never written back into an
 * input — a masked hint sits beside the field instead, and leaving it
 * blank keeps whatever is stored.
 */
$panel = function (string $code, array $row, array $hint, array $required, bool $isProvider, ?Provider $provider = null) {
    ?>
    <section class="card provider" data-provider="<?= e($code) ?>">
        <div class="card-head">
            <h2><?= e(code_label('api_provider', $code)) ?></h2>
            <?php
            $available = $isProvider
                ? ($provider?->isAvailable() ?? false)
                : (!empty($row['is_enabled']) && $hint['client_id'] !== '' && $hint['client_secret'] !== '');
            $configured = true;

            foreach ($required as $field) {
                $configured = $configured && $hint[$field] !== '';
            }
            ?>
            <span class="badge <?= $available ? 'ok' : ($configured ? 'warn' : 'off') ?>">
                <?= e($available ? __('ui.label.available')
                    : ($configured ? __('ui.label.disabled') : __('ui.label.needs_credentials'))) ?>
            </span>
        </div>

        <p class="muted"><?= e(__('ui.api_hint.' . $code)) ?></p>

        <form method="post" action="<?= e(url('/admin/api')) ?>" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="provider" value="<?= e($code) ?>">

            <label class="inline">
                <input type="checkbox" name="is_enabled" value="1" <?= !empty($row['is_enabled']) ? 'checked' : '' ?>>
                <span><?= e(__('ui.field.enabled')) ?></span>
            </label>

            <?php if ($isProvider): ?>
                <label class="inline">
                    <input type="checkbox" name="is_default" value="1" <?= !empty($row['is_default']) ? 'checked' : '' ?>>
                    <span><?= e(__('ui.field.is_default')) ?>
                          <small class="muted"><?= e(__('ui.label.is_default_hint')) ?></small></span>
                </label>
            <?php endif; ?>

            <?php if ($required === []): ?>
                <p class="muted small"><?= e(__('ui.label.no_credentials_needed')) ?></p>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($required as $field): ?>
                        <label>
                            <span><?= e(__('ui.field.' . $field)) ?></span>
                            <input type="text" name="<?= e($field) ?>" value="" class="key-field masked"
                                   autocomplete="off" spellcheck="false" data-lpignore="true"
                                   placeholder="<?= e($hint[$field] !== ''
                                        ? sprintf(__('ui.label.stored_secret'), $hint[$field])
                                        : __('ui.label.not_set')) ?>">
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="muted small"><?= e(__('ui.message.secret_blank_keeps')) ?></p>
            <?php endif; ?>

            <div class="card-actions">
                <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
                <button type="button" class="btn test-provider" data-provider="<?= e($code) ?>">
                    <?= e(__('ui.action.test_connection')) ?>
                </button>
                <?php if ($required !== [] && $configured): ?>
                    <button type="submit" name="clear_credentials" value="1" class="btn">
                        <?= e(__('ui.action.forget_credentials')) ?>
                    </button>
                <?php endif; ?>
                <span class="test-result muted">
                    <?php if (!empty($row['last_tested_at'])): ?>
                        <?= $row['last_test_ok'] ? '✔' : '✗' ?>
                        <?= e((string) $row['last_test_note']) ?>
                        <small>(<?= e(fmt_datetime($row['last_tested_at'])) ?>)</small>
                    <?php endif; ?>
                </span>
            </div>
        </form>
    </section>
    <?php
};
?>
<h1><?= e(__('ui.nav.api_settings')) ?></h1>
<p class="muted"><?= e(__('ui.message.api_intro')) ?></p>

<?php if (!$cryptoReady): ?>
    <div class="flash flash-error"><?= e(__('ui.message.no_app_key')) ?></div>
<?php endif; ?>

<h2 class="section-head"><?= e(__('ui.label.game_catalogues')) ?></h2>

<?php foreach ($providers as $code => $provider): ?>
    <?php $panel($code, $settings[$code] ?? [], $hints[$code] ?? ['api_key' => '', 'client_id' => '', 'client_secret' => ''],
                 $provider->requiredCredentials(), true, $provider); ?>
<?php endforeach; ?>

<h2 class="section-head"><?= e(__('ui.label.other_integrations')) ?></h2>

<?php $panel('twitch', $settings['twitch'] ?? [],
             $hints['twitch'] ?? ['api_key' => '', 'client_id' => '', 'client_secret' => ''],
             ['client_id', 'client_secret'], false); ?>

<section class="card">
    <h2><?= e(__('ui.label.twitch_redirect')) ?></h2>
    <p class="muted small"><?= e(__('ui.message.twitch_redirect_hint')) ?></p>
    <?php foreach (TwitchUser::redirectUris() as $uri): ?>
        <input type="text" readonly value="<?= e($uri) ?>" data-select-on-click class="redirect-uri">
    <?php endforeach; ?>
</section>
