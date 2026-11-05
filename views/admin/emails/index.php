<?php
/**
 * The system messages: what each is for, and its texts in each language
 * (marked when changed here).
 *
 * @var string $tab @var array<string, array<string, int>> $edited code => locale => fields changed @var list<string> $locales
 */
require __DIR__ . '/_tabs.php';
?>
<p class="muted"><?= e(__('ui.email_admin.messages_intro')) ?></p>

<div class="email-templates">
    <?php foreach (EmailTemplates::CODES as $code => $rule): ?>
        <section class="card email-template">
            <div>
                <h2><?= e(__('ui.email_admin.code_' . $code)) ?></h2>
                <p class="muted small"><?= e(__('ui.email_admin.code_' . $code . '_hint')) ?></p>
                <p class="email-template-tags">
                    <?php if ($rule['verified']): ?><span class="badge"><?= e(__('ui.email_admin.tag_verified')) ?></span><?php endif; ?>
                    <?php if ($rule['optional']): ?><span class="badge"><?= e(__('ui.email_admin.tag_optional')) ?></span><?php endif; ?>
                </p>
            </div>
            <div class="email-template-langs">
                <?php foreach ($locales as $loc): ?>
                    <a class="btn small" href="<?= e(url('/admin/emails/edit?code=' . $code . '&locale=' . urlencode($loc))) ?>">
                        <?= e($loc) ?>
                        <?php if (!empty($edited[$code][$loc])): ?><span class="badge warn"><?= e(__('ui.label.lang_edited_one')) ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
