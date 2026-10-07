<?php /* Visitors' language switcher: English · Português. None where the domain's language is fixed. */ ?>
<?php if (Lang::switchable()): ?>
<nav class="lang-switch" aria-label="<?= e(__('ui.field.language')) ?>">
    <?php $first = true; foreach (LocaleController::links() as $locale => $link): ?>
        <?php if (!$first): ?><span aria-hidden="true">·</span><?php endif; $first = false; ?>
        <?php if ($link['current']): ?>
            <strong lang="<?= e($locale) ?>" aria-current="true"><?= e($link['label']) ?></strong>
        <?php else: ?>
            <a href="<?= e($link['href']) ?>" lang="<?= e($locale) ?>" hreflang="<?= e($locale) ?>" data-turbo="false"><?= e($link['label']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
