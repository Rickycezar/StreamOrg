<?php
/** @var array $locales @var string $locale @var ?string $reference
 *  @var array $groups @var string $group @var array $entries @var array $refRows
 *  @var string $search @var array $codeRows @var int $missingTotal @var array $counts */
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

<?php if ($codeRows !== []): ?>
<form class="card" id="code-labels" method="post" action="<?= e(url('/admin/lang/labels')) ?>">
    <?= Csrf::field() ?>
    <div class="card-head">
        <h2><?= e(__('ui.label.code_labels')) ?></h2>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </div>
    <p class="muted small"><?= e(__('ui.message.code_labels_intro')) ?></p>

    <?php foreach ($codeRows as $grp => $codes): ?>
        <h3 class="section-head"><?= e(__('ui.label.code_group_' . $grp)) ?> <small class="muted"><code><?= e($grp) ?></code></small></h3>
        <div class="table-wrap">
            <table class="langtable code-labels">
                <thead>
                <tr>
                    <th><?= e(__('ui.field.code')) ?></th>
                    <?php foreach ($locales as $loc): ?><th><?= e($loc) ?></th><?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($codes as $code => $cells): ?>
                    <tr>
                        <td class="nowrap"><code><?= e($code) ?></code></td>
                        <?php foreach ($locales as $loc): $cell = $cells[$loc]; ?>
                            <td>
                                <?php if ($cell['file'] !== null): ?>
                                    <span class="file-label" title="<?= e(__('ui.label.from_lang_file')) ?>"><?= e($cell['file']) ?></span>
                                <?php else: ?>
                                    <input type="text" name="labels[<?= e($grp) ?>][<?= e($code) ?>][<?= e($loc) ?>]"
                                           value="<?= e((string) $cell['db']) ?>" placeholder="<?= e($code) ?>"
                                           maxlength="120" autocomplete="off" class="<?= $cell['db'] === null ? 'needs-label' : '' ?>">
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
    <p class="muted small"><?= e(__('ui.message.code_labels_blank')) ?></p>
</form>
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
