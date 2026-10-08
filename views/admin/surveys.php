<?php
/**
 * Administration → Questionnaires: every one with its status, how many of
 * its audience answered, and what can be done (edit, see results, open,
 * close, delete).
 *
 * @var list<array> $surveys
 */
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.surveys')) ?></h1>
    <a class="btn primary" href="<?= e(url('/admin/surveys/edit')) ?>">+ <?= e(__('ui.support.survey_new')) ?></a>
</div>
<p class="muted"><?= e(__('ui.support.surveys_intro')) ?></p>

<?php if ($surveys === []): ?>
    <section class="card"><p class="empty"><?= e(__('ui.support.surveys_admin_none')) ?></p></section>
<?php else: ?>
    <div class="survey-cards">
        <?php foreach ($surveys as $s):
            $size = max(1, (int) $s['audience_size']);
            $pct  = min(100, (int) round((int) $s['responses'] * 100 / $size)); ?>
            <article class="card survey-card status-<?= e($s['status']) ?>">
                <header>
                    <span class="badge survey-<?= e($s['status']) ?>"><?= e(__('ui.survey_status.' . $s['status'])) ?></span>
                    <h2><a href="<?= e(url('/admin/surveys/edit?id=' . (int) $s['id'])) ?>"><?= e($s['title']) ?></a></h2>
                    <span class="survey-langs">
                        <?php foreach ($s['missing'] as $locale => $missing): ?>
                            <span class="badge <?= $missing === 0 ? 'ok' : 'warn' ?>" title="<?= e($missing === 0 ? __('ui.support.lang_complete') : sprintf(__('ui.support.lang_missing'), $missing)) ?>"><?= e(Lang::t('ui.label.language_name', $locale)) ?><?= $missing === 0 ? ' ✓' : ' · ' . $missing ?></span>
                        <?php endforeach; ?>
                    </span>
                </header>
                <p class="muted small">
                    <?= e(sprintf(__('ui.support.n_questions'), (int) $s['questions'])) ?> ·
                    <?= e(__($s['audience'] === 'everyone' ? 'ui.support.audience_everyone' : 'ui.support.audience_users_short')) ?>
                    <?php if ($s['closes_at']): ?> · <?= e(sprintf(__('ui.support.closes_on'), fmt_datetime($s['closes_at'], 'd/m H:i'))) ?><?php endif; ?>
                </p>
                <div class="survey-meter" title="<?= $pct ?>%"><span style="width: <?= $pct ?>%"></span></div>
                <p class="small"><?= e(sprintf(__('ui.support.n_answered_of'), (int) $s['responses'], (int) $s['audience_size'])) ?></p>
                <div class="card-actions">
                    <a class="btn small" href="<?= e(url('/admin/surveys/edit?id=' . (int) $s['id'])) ?>"><?= e(__('ui.action.edit')) ?></a>
                    <?php if ((int) $s['responses'] > 0): ?>
                        <a class="btn small" href="<?= e(url('/admin/surveys/results?id=' . (int) $s['id'])) ?>"><?= e(__('ui.support.results')) ?></a>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('/admin/surveys/status')) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <?php if ($s['status'] === 'open'): ?>
                            <input type="hidden" name="status" value="closed">
                            <button type="submit" class="btn small"><?= e(__('ui.support.survey_close')) ?></button>
                        <?php else: ?>
                            <input type="hidden" name="status" value="open">
                            <button type="submit" class="btn small primary"><?= e(__($s['status'] === 'closed' ? 'ui.support.survey_reopen' : 'ui.support.survey_open')) ?></button>
                        <?php endif; ?>
                    </form>
                    <form method="post" action="<?= e(url('/admin/surveys/status')) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <input type="hidden" name="status" value="delete">
                        <button type="submit" class="btn small danger-outline" data-confirm="<?= e(__('ui.support.survey_delete_confirm')) ?>"><?= e(__('ui.action.delete')) ?></button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
