<?php
/**
 * Administration → a questionnaire's results: for each question what
 * people answered (bars for choices, yes/no and scales, with the average;
 * every written answer with its author), then who answered and when, with
 * a spreadsheet export of every response.
 *
 * @var array $survey @var list<array> $responses @var list<array> $questions
 */
?>
<div class="page-head">
    <h1><?= e($survey['title']) ?> <span class="badge survey-<?= e($survey['status']) ?>"><?= e(__('ui.survey_status.' . $survey['status'])) ?></span></h1>
    <span class="page-head-actions">
        <a class="btn small" href="<?= e(url('/admin/surveys/export?id=' . (int) $survey['id'])) ?>" data-turbo="false"><?= e(__('ui.support.export_csv')) ?></a>
        <a class="btn small" href="<?= e(url('/admin/surveys')) ?>">← <?= e(__('ui.nav.surveys')) ?></a>
    </span>
</div>
<p class="muted"><?= e(sprintf(__('ui.support.n_responses'), count($responses))) ?></p>

<?php $group = null; foreach ($questions as $i => $q):
    $s = $q['summary']; ?>
    <?php if ($q['group'] !== $group): $group = $q['group']; ?>
        <?php if ($group !== ''): ?><h2 class="results-group"><?= e($group) ?></h2><?php endif; ?>
    <?php endif; ?>
    <section class="card result-card">
        <header>
            <span class="survey-num"><?= $i + 1 ?></span>
            <h3><?= e($q['label']) ?></h3>
            <span class="muted small"><?= e(__('ui.survey_type.' . $q['type'])) ?> · <?= e(sprintf(__('ui.support.n_answered'), (int) $s['answered'])) ?></span>
        </header>

        <?php if (isset($s['average'])): ?>
            <p class="result-average"><strong><?= e((string) $s['average']) ?></strong> <span class="muted"><?= e(__('ui.support.average')) ?> · <?= e(sprintf(__('ui.support.min_max'), (string) $s['min'], (string) $s['max'])) ?></span></p>
        <?php endif; ?>

        <?php if (isset($s['counts'])):
            $most = max([1, ...array_values($s['counts'])]); ?>
            <ul class="result-bars">
                <?php foreach ($s['counts'] as $key => $count):
                    $pct = $s['answered'] > 0 ? round($count * 100 / $s['answered']) : 0; ?>
                    <li>
                        <span class="result-label"><?= e((string) ($s['labels'][$key] ?? $key)) ?></span>
                        <span class="result-bar"><span style="width: <?= round($count * 100 / $most) ?>%"></span></span>
                        <span class="result-count"><?= (int) $count ?> <small class="muted">(<?= $pct ?>%)</small></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($s['texts'])): ?>
            <ul class="result-texts">
                <?php foreach ($s['texts'] as $t): ?>
                    <li><p><?= nl2br(e($t['value'])) ?></p><small class="muted">— <?= e($t['name']) ?></small></li>
                <?php endforeach; ?>
            </ul>
        <?php elseif ($s['answered'] === 0): ?>
            <p class="muted"><?= e(__('ui.support.no_answers')) ?></p>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<section class="card">
    <h2><?= e(__('ui.support.who_answered')) ?></h2>
    <?php if ($responses === []): ?>
        <p class="empty"><?= e(__('ui.support.no_answers')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table data-sortable>
                <thead><tr><th><?= e(__('ui.support.r_person')) ?></th><th><?= e(__('ui.support.r_when')) ?></th><th><?= e(__('ui.support.r_answers')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($responses as $r): ?>
                    <tr>
                        <td><?= e((string) $r['name']) ?></td>
                        <td data-sort="<?= e(sort_key($r['updated_at'])) ?>"><?= e(fmt_datetime($r['updated_at'])) ?></td>
                        <td><?= count($r['answers']) ?>/<?= count($questions) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
