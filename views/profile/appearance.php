<?php
/** @var array $user @var array $themes */
?>
<section class="card">
    <h2><?= e(__('ui.field.theme')) ?></h2>

    <form method="post" action="<?= e(url('/profile/appearance')) ?>" class="subform">
        <?= Csrf::field() ?>
        <div class="themes">
            <?php foreach ($themes as $theme): ?>
                <label class="theme-option">
                    <input type="radio" name="theme" value="<?= e($theme) ?>"
                           <?= $user['theme'] === $theme ? 'checked' : '' ?>>
                    <span class="theme-swatch theme-<?= e($theme) ?>">
                        <span class="sw-bar"></span>
                        <span class="sw-body">
                            <span class="sw-line"></span>
                            <span class="sw-line short"></span>
                            <span class="sw-accent"></span>
                        </span>
                    </span>
                    <span class="theme-name"><?= e(code_label('theme', $theme)) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>
</section>
