<?php
/**
 * "How your keys are protected": the vault's security in plain words, with
 * links to the technical references. A document of its own, meant for a
 * small separate window, so it has no menu.
 *
 * @var string  $theme
 * @var ?string $mode  the signed-in user's vault mode, null for visitors
 */

$asset = static fn (string $path): string => url($path) . '?v=' . (@filemtime(dirname(__DIR__) . '/public' . $path) ?: 0);
$t     = static fn (string $key): string => __('ui.vault_security.' . $key);

$icon = static function (string $path): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"'
        . ' stroke-linejoin="round" aria-hidden="true"><path d="' . e($path) . '"/></svg>';
};

$points = [
    ['locked',    'M7 11V8a5 5 0 0 1 10 0v3M5 11h14v10H5zM12 15v2'],
    ['own_key',   'M14 10a4 4 0 1 0-3.9 4H11l1.5 1.5L14 14l1.5 1.5L17 14l2 2M7.5 10.5h.01'],
    ['tamper',    'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4'],
    ['apart',     'M4 7h7v10H4zM13 7h7v10h-7zM7.5 12h.01M16.5 12h.01'],
    ['password',  'M5 11h14v10H5zM8 11V8a4 4 0 0 1 8 0v3M9 16h.01M12 16h.01M15 16h.01'],
    ['transit',   'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9s1-6.5 3.5-9z'],
    ['hidden',    'M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6zM12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM4 4l16 16'],
    ['sessions',  'M4 5h16v11H4zM8 20h8M12 16v4'],
];

$references = [
    ['ref_aes',       'https://en.wikipedia.org/wiki/Advanced_Encryption_Standard'],
    ['ref_gcm',       'https://csrc.nist.gov/pubs/sp/800/38/d/final'],
    ['ref_envelope',  'https://docs.cloud.google.com/kms/docs/envelope-encryption'],
    ['ref_pbkdf2',    'https://www.rfc-editor.org/rfc/rfc8018'],
    ['ref_owasp',     'https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html'],
    ['ref_argon2',    'https://www.rfc-editor.org/rfc/rfc9106'],
    ['ref_hsts',      'https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Strict-Transport-Security'],
    ['ref_csp',       'https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP'],
];
?>
<!DOCTYPE html>
<html lang="<?= e(Lang::locale()) ?>" data-theme="<?= e($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($t('title')) ?> · <?= e(__('ui.app_name')) ?></title>
    <link rel="icon" href="<?= e(url('/assets/brand/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e($asset('/assets/app.css')) ?>">
    <script src="<?= e($asset('/assets/popup.js')) ?>" defer></script>
</head>
<body class="sec-page">
<main class="sec">
    <header class="sec-hero">
        <span class="sec-shield"><?= $icon('M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4') ?></span>
        <div>
            <h1><?= e($t('title')) ?></h1>
            <p class="sec-lead"><?= e($t('lead')) ?></p>
        </div>
        <button type="button" class="btn small sec-close hidden" data-close-window><?= e(__('ui.action.close')) ?></button>
    </header>

    <?php if ($mode !== null): ?>
        <p class="sec-yours">
            <?= e($t('your_mode')) ?>
            <strong><?= e(__($mode === 'private' ? 'ui.label.vault_private' : 'ui.label.vault_managed')) ?></strong>.
            <?= e($t('change_mode')) ?>
        </p>
    <?php endif; ?>

    <section>
        <h2><?= e($t('plain_title')) ?></h2>
        <div class="sec-points">
            <?php foreach ($points as [$key, $path]): ?>
                <article class="sec-point">
                    <span class="sec-icon"><?= $icon($path) ?></span>
                    <div>
                        <h3><?= e($t($key . '_title')) ?></h3>
                        <p><?= e($t($key . '_text')) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section>
        <h2><?= e($t('modes_title')) ?></h2>
        <p class="muted"><?= e($t('modes_intro')) ?></p>
        <div class="sec-modes-wrap">
            <table class="sec-modes">
                <thead>
                <tr>
                    <th></th>
                    <th><?= e(__('ui.label.vault_managed')) ?><?= $mode === 'managed' ? ' <span class="badge ok">' . e($t('yours')) . '</span>' : '' ?></th>
                    <th><?= e(__('ui.label.vault_private')) ?><?= $mode === 'private' ? ' <span class="badge ok">' . e($t('yours')) . '</span>' : '' ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (['unlock', 'forgot', 'server', 'best'] as $row): ?>
                    <tr>
                        <th scope="row"><?= e($t('row_' . $row)) ?></th>
                        <td><?= e($t('managed_' . $row)) ?></td>
                        <td><?= e($t('private_' . $row)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="sec-honest">
        <h2><?= e($t('limits_title')) ?></h2>
        <ul>
            <li><?= e($t('limit_password')) ?></li>
            <li><?= e($t('limit_screen')) ?></li>
            <li><?= e($t('limit_device')) ?></li>
        </ul>
    </section>

    <section>
        <h2><?= e($t('refs_title')) ?></h2>
        <p class="muted"><?= e($t('refs_intro')) ?></p>
        <ul class="sec-refs">
            <?php foreach ($references as [$key, $href]): ?>
                <li>
                    <a href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer"><?= e($t($key)) ?></a>
                    <small class="muted"><?= e($t($key . '_what')) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</main>
</body>
</html>
