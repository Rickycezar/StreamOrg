<?php
/** @var array $locales @var string $locale @var ?string $reference
 *  @var array $groups @var string $group @var array $entries @var array $refRows
 *  @var string $search @var array $missing @var array $counts */

$missingTotal = array_sum(array_map('count', $missing));
?>
<h1><?= e(__('ui.nav.languages')) ?></h1>
<p class="muted"><?= e(__('ui.message.lang_intro')) ?></p>

<section class="tiles">
    <?php foreach ($locales as $loc): ?>
        <a class="tile <?= $loc === $locale ? 'active' : '' ?>"
           href="<?= e(url('/admin/lang?locale=' . urlencode($loc))) ?>">
            <span class="tile-value"><?= e($loc) ?></span>
            <span class="tile-label">
                <?= $loc === $locale ? (int) $counts['keys'] : '' ?>
                <?= e(__('ui.label.lang_keys')) ?>
            </span>
        </a>
    <?php endforeach; ?>
    <span class="tile" title="<?= e(__('ui.label.lang_missing_hint')) ?>">
        <span class="tile-value <?= $missingTotal > 0 ? 'warn' : '' ?>"><?= $missingTotal ?></span>
        <span class="tile-label"><?= e(__('ui.label.lang_missing')) ?></span>
    </span>
</section>

<?php if ($missingTotal > 0): ?>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.lang_missing')) ?></h2>
    </div>
    <p class="muted small"><?= e(__('ui.message.lang_missing_intro')) ?></p>

    <?php foreach ($missing as $grp => $codes): ?>
        <form method="post" action="<?= e(url('/admin/lang')) ?>" class="subform">
            <?= Csrf::field() ?>
            <input type="hidden" name="locale" value="<?= e($locale) ?>">
            <input type="hidden" name="group" value="<?= e($grp) ?>">
            <h3 class="section-head"><?= e($grp) ?></h3>
            <div class="grid">
                <?php foreach ($codes as $code): ?>
                    <label>
                        <span><code><?= e($code) ?></code></span>
                        <input type="text" name="values[<?= e($code) ?>]"
                               placeholder="<?= e($code) ?>" autocomplete="off">
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn primary small"><?= e(__('ui.action.save')) ?></button>
        </form>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<form class="card filters" method="get" action="<?= e(url('/admin/lang')) ?>">
    <input type="hidden" name="locale" value="<?= e($locale) ?>">
    <label>
        <span><?= e(__('ui.field.group')) ?></span>
        <select name="group" data-autosubmit>
            <?php foreach ($groups as $g): ?>
                <option value="<?= e($g) ?>" <?= $g === $group ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="grow">
        <span><?= e(__('ui.action.search')) ?></span>
        <input type="search" name="q" value="<?= e($search) ?>">
    </label>
    <div class="filter-actions">
        <button type="submit" class="btn"><?= e(__('ui.action.filter')) ?></button>
        <a class="btn ghost" href="<?= e(url('/admin/lang?locale=' . urlencode($locale) . '&group=' . urlencode($group))) ?>">
            <?= e(__('ui.action.clear')) ?>
        </a>
    </div>
</form>

<form class="card" method="post" action="<?= e(url('/admin/lang')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="locale" value="<?= e($locale) ?>">
    <input type="hidden" name="group" value="<?= e($group) ?>">

    <div class="card-head">
        <h2><?= e($group) ?> · <?= e($locale) ?> (<?= count($entries) ?>)</h2>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </div>

    <?php if ($entries === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="langtable">
            <thead>
            <tr>
                <th><?= e(__('ui.field.code')) ?></th>
                <th><?= e($locale) ?></th>
                <?php if ($reference !== null): ?>
                    <th><?= e($reference) ?> <small class="muted"><?= e(__('ui.label.reference')) ?></small></th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $key => $value): ?>
                <tr>
                    <td class="nowrap"><code><?= e((string) $key) ?></code></td>
                    <td>
                        <input type="text" name="values[<?= e((string) $key) ?>]"
                               value="<?= e((string) $value) ?>" autocomplete="off" spellcheck="true">
                    </td>
                    <?php if ($reference !== null): ?>
                        <td class="muted refcell"><?= e((string) ($refRows[$key] ?? '—')) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="muted small"><?= e(__('ui.message.lang_blank_removes')) ?></p>
    <?php endif; ?>
</form>
