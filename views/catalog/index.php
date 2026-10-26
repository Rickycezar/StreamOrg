<?php
/** @var array $games @var array $filters @var int $needsDate
 *  @var ?Provider $provider @var ?string $providerCode @var bool $twitchFirst */
?>
<h1><?= e(__('ui.nav.catalog')) ?></h1>
<p class="muted"><?= e(__('ui.message.catalog_intro')) ?></p>

<?php $tab = ($_GET['tab'] ?? '') === 'add' ? 'add' : 'games'; ?>
<?= View::partial('partials/tabs', ['name' => 'games', 'active' => $tab, 'tabs' => [
    'games' => __('ui.nav.games'),
    'add' => __('ui.label.tab_add_game'),
]]) ?>

<div data-tab-panel="add" <?= $tab === 'add' ? '' : 'hidden' ?>>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.action.create')) ?></h2>
        <?php if ($twitchFirst): ?>
            <span class="badge twitch-cat"><?= e(__('ui.label.via_twitch')) ?></span>
        <?php elseif ($provider !== null): ?>
            <span class="badge ok"><?= e(sprintf(__('ui.label.via_provider'), code_label('api_provider', $providerCode))) ?></span>
        <?php endif; ?>
    </div>

    <?php if (!$twitchFirst && $provider === null): ?>
        <p class="empty"><?= e(__('ui.message.no_default_provider')) ?></p>
    <?php else: ?>
        <?php if ($twitchFirst): ?>
            <p class="muted small"><?= e(__('ui.message.add_game_twitch_first')) ?></p>
        <?php endif; ?>
        <label>
            <span><?= e(__('ui.action.search')) ?></span>
            <input type="search" id="catalog-search" autocomplete="off"
                   placeholder="<?= e(__($twitchFirst ? 'ui.label.search_twitch_hint' : 'ui.label.search_hint')) ?>">
        </label>
        <div id="catalog-results" class="results"></div>
    <?php endif; ?>
</section>
</div>

<div data-tab-panel="games" <?= $tab === 'games' ? '' : 'hidden' ?>>
<form class="card filters" method="get" action="<?= e(url('/catalog')) ?>" data-filters>
    <?= View::partial('partials/filter_search', ['value' => $filters['q'], 'placeholder' => __('ui.label.search_games')]) ?>
    <?= View::partial('partials/filter_select', [
        'name' => 'stale', 'label' => __('ui.field.release_date'), 'selected' => $filters['stale'], 'multiple' => false,
        'options' => ['1' => __('ui.label.needs_date') . ' (' . $needsDate . ')'],
    ]) ?>
    <?= View::partial('partials/filter_actions', ['clear' => '/catalog', 'active' => $filters['q'] !== '' || $filters['stale'] !== '']) ?>
</form>

<section class="card">
    <h2><?= e(__('ui.nav.games')) ?> (<?= count($games) ?>)</h2>
    <?php if ($games === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table data-sortable data-table="catalog">
            <thead>
            <tr>
                <th class="col-actions"><span class="visually-hidden"><?= e(__('ui.label.actions')) ?></span></th>
                <th data-col="title"><?= e(__('ui.field.title')) ?></th>
                <th data-col="publisher"><?= e(__('ui.nav.publishers')) ?></th>
                <th data-col="developer"><?= e(__('ui.nav.developers')) ?></th>
                <th data-col="release"><?= e(__('ui.field.release_date')) ?></th>
                <th data-col="source"><?= e(__('ui.field.source')) ?></th>
                <th data-col="keys"><?= e(__('ui.nav.keys')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($games as $game): ?>
                <tr data-game-id="<?= (int) $game['id'] ?>"<?= row_art($game['cover_path'], $game['cover_version']) ?>>
                    <td class="rowactions">
                        <?php if ($game['source_provider']): ?>
                            <button type="button" class="btn small game-refresh" data-id="<?= (int) $game['id'] ?>"
                                    title="<?= e(__('ui.action.refresh_hint')) ?>">
                                <?= icon_label('refresh', __('ui.action.refresh')) ?>
                            </button>
                        <?php else: ?>
                            <span class="muted small"><?= e(__('ui.label.manual_entry')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-card-title data-col="title" data-label="<?= e(__('ui.field.title')) ?>">
                        <img class="card-cover" loading="lazy" alt=""
                             src="<?= e($game['cover_path'] ? GameImages::publicUrl($game['cover_path'], (string) $game['cover_version']) : url('/assets/game-missing.svg')) ?>">
                        <span class="game-cell has-card-cover">
                            <?php if ($game['thumb_path']): ?>
                                <img class="picker-cover" alt="" loading="lazy"
                                     src="<?= e(GameImages::publicUrl($game['thumb_path'], (string) $game['thumb_version'])) ?>">
                            <?php else: ?>
                                <span class="picker-cover"></span>
                            <?php endif; ?>
                            <span class="game-title"><?= e($game['title']) ?></span>
                        </span>
                    </td>
                    <td data-col="publisher" data-label="<?= e(__('ui.nav.publishers')) ?>"><?= e($game['publisher_name'] ?: '—') ?></td>
                    <td data-col="developer" data-label="<?= e(__('ui.nav.developers')) ?>"><?= e($game['developer_name'] ?: '—') ?></td>
                    <td class="release-cell" data-col="release" data-sort="<?= e(sort_key($game['release_date'])) ?>" data-label="<?= e(__('ui.field.release_date')) ?>">
                        <?php if ($game['release_date'] && $game['release_precision'] === 'day'): ?>
                            <?= e(fmt_date($game['release_date'])) ?>
                        <?php elseif ($game['release_date']): ?>
                            <?= e($game['release_raw'] ?: substr((string) $game['release_date'], 0, 4)) ?>
                            <span class="badge warn" title="<?= e(__('ui.label.imprecise_hint')) ?>">
                                <?= e(code_label('release_precision', $game['release_precision'])) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge warn"><?= e(__('ui.label.no_release_date')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-col="source" data-label="<?= e(__('ui.field.source')) ?>">
                        <?php if ($game['source_provider']): ?>
                            <?= e(code_label('api_provider', $game['source_provider'])) ?>
                            <?php if ($game['source_synced_at']): ?>
                                <small class="muted block"><?= e(fmt_date($game['source_synced_at'])) ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-col="keys" data-label="<?= e(__('ui.nav.keys')) ?>"><?= (int) $game['my_keys'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
</div>
