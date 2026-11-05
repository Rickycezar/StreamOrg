<?php
/**
 * One system message in one language: its fields, each with the text it
 * ships with, the {placeholders} it may use, and a preview that follows
 * the typing (sample values, not saved until "Save").
 *
 * @var string $tab @var string $code @var string $locale @var list<string> $locales
 * @var array<string, string> $texts @var array<string, string> $defaults @var array<string, string> $overrides
 * @var array{subject:string, html:string, text:string} $preview
 */
require __DIR__ . '/_tabs.php';
$rule = EmailTemplates::CODES[$code];
?>
<p class="muted small"><a href="<?= e(url('/admin/emails')) ?>">← <?= e(__('ui.email_admin.tab_messages')) ?></a></p>

<div class="email-editor" data-email-editor>
    <form class="card" method="post" action="<?= e(url('/admin/emails/save')) ?>" data-email-form>
        <?= Csrf::field() ?>
        <input type="hidden" name="code" value="<?= e($code) ?>">
        <input type="hidden" name="locale" value="<?= e($locale) ?>">
        <div class="card-head">
            <h2><?= e(__('ui.email_admin.code_' . $code)) ?></h2>
            <nav class="email-langs">
                <?php foreach ($locales as $loc): ?>
                    <a class="btn small <?= $loc === $locale ? 'primary' : '' ?>" href="<?= e(url('/admin/emails/edit?code=' . $code . '&locale=' . urlencode($loc))) ?>"><?= e($loc) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <p class="muted small"><?= e(__('ui.email_admin.code_' . $code . '_hint')) ?></p>
        <p class="email-placeholders small">
            <strong><?= e(__('ui.email_admin.placeholders')) ?></strong>
            <?php foreach ($rule['placeholders'] as $p): ?><code>{<?= e($p) ?>}</code> <?php endforeach; ?>
            <span class="muted"><?= e(__('ui.email_admin.formatting')) ?></span>
        </p>

        <?php foreach (EmailTemplates::FIELDS as $field):
            if ($field === 'button' && !$rule['button']) continue;
            $changed = isset($overrides[$field]); ?>
            <label>
                <span><?= e(__('ui.email_admin.field_' . $field)) ?>
                    <?php if ($changed): ?><span class="badge warn"><?= e(__('ui.label.lang_edited_one')) ?></span><?php endif; ?></span>
                <?php if ($field === 'body' || $field === 'note'): ?>
                    <textarea name="texts[<?= e($field) ?>]" rows="<?= $field === 'body' ? 8 : 3 ?>" maxlength="<?= EmailTemplates::LIMITS[$field] ?>"><?= e($texts[$field]) ?></textarea>
                <?php else: ?>
                    <input type="text" name="texts[<?= e($field) ?>]" value="<?= e($texts[$field]) ?>" maxlength="<?= EmailTemplates::LIMITS[$field] ?>">
                <?php endif; ?>
                <?php if ($changed): ?><small class="muted lang-default"><?= e(__('ui.label.lang_default')) ?>: <?= e($defaults[$field]) ?></small><?php endif; ?>
                <?php if ($field === 'preheader'): ?><small class="muted"><?= e(__('ui.email_admin.field_preheader_hint')) ?></small><?php endif; ?>
            </label>
        <?php endforeach; ?>

        <p class="muted small"><?= e(__('ui.email_admin.blank_default')) ?></p>
        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
            <button type="submit" class="btn" form="email-test"><?= e(__('ui.email_admin.send_test')) ?></button>
        </div>
    </form>
    <form id="email-test" method="post" action="<?= e(url('/admin/emails/test')) ?>" hidden>
        <?= Csrf::field() ?>
        <input type="hidden" name="code" value="<?= e($code) ?>">
        <input type="hidden" name="locale" value="<?= e($locale) ?>">
    </form>

    <section class="card email-preview">
        <div class="card-head">
            <h2><?= e(__('ui.email_admin.preview')) ?></h2>
            <div class="mode-switch email-preview-switch" role="tablist">
                <button type="button" class="btn small active" data-preview-show="html"><?= e(__('ui.email_admin.preview_html')) ?></button>
                <button type="button" class="btn small" data-preview-show="text"><?= e(__('ui.email_admin.preview_text')) ?></button>
            </div>
        </div>
        <p class="email-preview-subject"><span class="muted small"><?= e(__('ui.email_admin.field_subject')) ?>:</span> <strong data-preview-subject><?= e($preview['subject']) ?></strong></p>
        <iframe class="email-frame" title="<?= e(__('ui.email_admin.preview')) ?>" sandbox="" data-preview-html srcdoc="<?= e($preview['html']) ?>"></iframe>
        <pre class="email-text" data-preview-text hidden><?= e($preview['text']) ?></pre>
        <p class="muted small"><?= e(__('ui.email_admin.preview_hint')) ?></p>
    </section>
</div>
