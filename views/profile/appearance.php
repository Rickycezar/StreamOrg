<?php
/** @var array $user @var array<string, array{0:string, 1:string}> $families @var list<string> $modes */

$current = Themes::forUser($user);
$family  = Themes::isFamily((string) $user['theme']) ? (string) $user['theme'] : Themes::DEFAULT_FAMILY;
?>
<form method="post" action="<?= e(url('/profile/appearance')) ?>" class="card appearance" data-appearance>
    <?= Csrf::field() ?>

    <h2><?= e(__('ui.label.theme_mode')) ?></h2>
    <p class="muted small"><?= e(__('ui.message.theme_mode_explain')) ?></p>
    <div class="segmented" role="radiogroup" aria-label="<?= e(__('ui.label.theme_mode')) ?>">
        <?php foreach ($modes as $mode): ?>
            <label class="segment">
                <input type="radio" name="theme_mode" value="<?= e($mode) ?>" <?= $current['mode'] === $mode ? 'checked' : '' ?>>
                <span><?= e(__('ui.label.mode_' . $mode)) ?></span>
            </label>
        <?php endforeach; ?>
    </div>

    <h2 class="appearance-themes"><?= e(__('ui.field.theme')) ?></h2>
    <div class="themes">
        <?php foreach ($families as $name => [$light, $dark]): ?>
            <label class="theme-option">
                <input type="radio" name="theme" value="<?= e($name) ?>" <?= $family === $name ? 'checked' : '' ?>
                       data-light="<?= e($light) ?>" data-dark="<?= e($dark) ?>">
                <span class="theme-pair">
                    <?php foreach ([$light, $dark] as $variant): ?>
                        <span class="theme-swatch theme-<?= e($variant) ?>">
                            <span class="sw-bar"></span>
                            <span class="sw-body">
                                <span class="sw-line"></span>
                                <span class="sw-line short"></span>
                                <span class="sw-accent"></span>
                            </span>
                        </span>
                    <?php endforeach; ?>
                </span>
                <span class="theme-name"><?= e(code_label('theme', $name)) ?></span>
            </label>
        <?php endforeach; ?>
    </div>

    <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
</form>
