<?php
/**
 * Administration → Game texts: one game's texts in one language, each
 * with the game's own text (and the English one) beside it; changed texts
 * are marked, a blank one goes back to the game's.
 *
 * @var array<string, array> $games the hosted games' manifests @var string $game @var list<string> $languages
 * @var string $locale @var list<string> $shipped the languages the game ships @var list<string> $sections
 * @var string $section @var string $search @var bool $changed @var list<array> $rows @var int $total
 * @var int $missing texts with neither the language's own nor a change @var int $changeCount @var string $publicUrl
 */
?>
<h1><?= e(__('ui.nav.game_texts')) ?></h1>
<p class="muted"><?= e(__('ui.game_texts.intro')) ?></p>

<?php if ($games === []): ?>
    <p class="empty"><?= e(__('ui.game_texts.no_games')) ?></p>
<?php else:
    $here = static fn (array $change): string => url('/admin/game-texts?' . http_build_query($change + ['game' => $game, 'locale' => $locale]));
?>
<?php if (count($games) > 1): ?>
    <section class="tiles">
        <?php foreach ($games as $name => $manifest): ?>
            <a class="tile <?= $name === $game ? 'active' : '' ?>" href="<?= e(url('/admin/game-texts?game=' . urlencode($name))) ?>">
                <span class="tile-value"><?= e($name) ?></span>
                <span class="tile-label"><?= e((string) $manifest['version']) ?></span>
            </a>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<section class="tiles">
    <?php foreach ($languages as $loc): ?>
        <a class="tile <?= $loc === $locale ? 'active' : '' ?>" href="<?= e($here(['locale' => $loc])) ?>">
            <span class="tile-value"><?= e($loc) ?></span>
            <span class="tile-label"><?= e(in_array($loc, $shipped, true) ? __('ui.game_texts.shipped') : __('ui.game_texts.added')) ?></span>
        </a>
    <?php endforeach; ?>
    <span class="tile" title="<?= e(__('ui.game_texts.changed_hint')) ?>">
        <span class="tile-value"><?= (int) $changeCount ?></span>
        <span class="tile-label"><?= e(__('ui.label.lang_edited')) ?></span>
    </span>
    <span class="tile" title="<?= e(__('ui.game_texts.missing_hint')) ?>">
        <span class="tile-value <?= $missing > 0 ? 'warn' : '' ?>"><?= (int) $missing ?></span>
        <span class="tile-label"><?= e(__('ui.game_texts.missing')) ?></span>
    </span>
</section>

<div class="card game-texts-head">
    <p class="muted small">
        <?= e(sprintf(__('ui.game_texts.version'), $game, (string) $games[$game]['version'])) ?>
        · <a href="<?= e($publicUrl . '?lang=' . urlencode($locale)) ?>" target="_blank" rel="noopener"><?= e(__('ui.game_texts.open_public')) ?> ↗</a>
    </p>
    <form method="get" action="<?= e(url('/admin/game-texts')) ?>" class="game-texts-add">
        <input type="hidden" name="game" value="<?= e($game) ?>">
        <label>
            <span><?= e(__('ui.game_texts.add_language')) ?></span>
            <input type="text" name="locale" required pattern="[A-Za-z]{2}(-[A-Za-z0-9]{2,8})?" maxlength="12" placeholder="es" autocomplete="off" spellcheck="false">
        </label>
        <button type="submit" class="btn"><?= e(__('ui.game_texts.add_language_go')) ?></button>
    </form>
    <small class="muted"><?= e(__('ui.game_texts.add_language_hint')) ?></small>
</div>

<form class="card filters" method="get" action="<?= e(url('/admin/game-texts')) ?>" data-filters>
    <input type="hidden" name="game" value="<?= e($game) ?>">
    <input type="hidden" name="locale" value="<?= e($locale) ?>">
    <?= View::partial('partials/filter_select', [
        'name' => 'section', 'label' => __('ui.game_texts.section'), 'selected' => $section, 'multiple' => false,
        'options' => array_combine($sections, $sections),
    ]) ?>
    <?= View::partial('partials/filter_select', [
        'name' => 'changed', 'label' => __('ui.game_texts.show'), 'selected' => $changed ? '1' : '', 'multiple' => false,
        'options' => ['1' => __('ui.game_texts.only_changed')],
    ]) ?>
    <?= View::partial('partials/filter_search', ['value' => $search]) ?>
    <?= View::partial('partials/filter_actions', ['clear' => '/admin/game-texts?game=' . urlencode($game) . '&locale=' . urlencode($locale), 'active' => $section !== '' || $search !== '' || $changed]) ?>
</form>

<form class="card" method="post" action="<?= e(url('/admin/game-texts')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="game" value="<?= e($game) ?>">
    <input type="hidden" name="locale" value="<?= e($locale) ?>">
    <input type="hidden" name="section" value="<?= e($section) ?>">
    <input type="hidden" name="q" value="<?= e($search) ?>">
    <input type="hidden" name="changed" value="<?= $changed ? '1' : '' ?>">

    <div class="card-head">
        <h2><?= e($game) ?> · <?= e($locale) ?> (<?= count($rows) ?>/<?= (int) $total ?>)</h2>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </div>

    <?php if ($rows === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="langtable">
                <thead>
                <tr>
                    <th><?= e(__('ui.field.code')) ?></th>
                    <th><?= e($locale) ?></th>
                    <?php if ($locale !== 'en'): ?><th>en <small class="muted"><?= e(__('ui.label.reference')) ?></small></th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="nowrap">
                            <code><?= e($row['key']) ?></code>
                            <?php if ($row['changed']): ?><span class="badge warn"><?= e(__('ui.label.lang_edited_one')) ?></span><?php endif; ?>
                            <?php if ($row['placeholders'] !== []): ?>
                                <small class="muted game-text-needs" title="<?= e(__('ui.game_texts.placeholders_hint')) ?>"><?= e(implode(' ', array_map(static fn (string $p): string => '{' . $p . '}', $row['placeholders']))) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="text" name="values[<?= e($row['key']) ?>]" value="<?= e($row['value']) ?>" maxlength="500"
                                   placeholder="<?= e($row['default'] ?? $row['english']) ?>" autocomplete="off" spellcheck="true">
                            <?php if ($row['changed'] && $row['default'] !== null): ?><small class="muted lang-default"><?= e(__('ui.label.lang_default')) ?>: <?= e($row['default']) ?></small><?php endif; ?>
                        </td>
                        <?php if ($locale !== 'en'): ?><td class="muted refcell"><?= e($row['english']) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small"><?= e(__('ui.game_texts.blank')) ?></p>
    <?php endif; ?>
</form>
<?php endif; ?>
