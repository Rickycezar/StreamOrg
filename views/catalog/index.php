<?php
/** @var array $games @var array $filters @var int $needsDate
 *  @var ?Provider $provider @var ?string $providerCode @var bool $twitchFirst */
?>
<h1><?= e(__('ui.nav.catalog')) ?></h1>
<p class="muted"><?= e(__('ui.message.catalog_intro')) ?></p>

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

<form class="card filters" method="get" action="<?= e(url('/catalog')) ?>">
    <label class="grow">
        <span><?= e(__('ui.action.search')) ?></span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>">
    </label>
    <label>
        <span><?= e(__('ui.field.release_date')) ?></span>
        <select name="stale">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <option value="1" <?= $filters['stale'] === '1' ? 'selected' : '' ?>>
                <?= e(__('ui.label.needs_date')) ?> (<?= $needsDate ?>)
            </option>
        </select>
    </label>
    <div class="filter-actions">
        <button type="submit" class="btn"><?= e(__('ui.action.filter')) ?></button>
        <a class="btn ghost" href="<?= e(url('/catalog')) ?>"><?= e(__('ui.action.clear')) ?></a>
    </div>
</form>

<section class="card">
    <h2><?= e(__('ui.nav.games')) ?> (<?= count($games) ?>)</h2>
    <?php if ($games === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table data-table="catalog">
            <thead>
            <tr>
                <th data-col="title"><?= e(__('ui.field.title')) ?></th>
                <th data-col="publisher"><?= e(__('ui.nav.publishers')) ?></th>
                <th data-col="developer"><?= e(__('ui.nav.developers')) ?></th>
                <th data-col="release"><?= e(__('ui.field.release_date')) ?></th>
                <th data-col="source"><?= e(__('ui.field.source')) ?></th>
                <th data-col="keys"><?= e(__('ui.nav.keys')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($games as $game): ?>
                <tr data-game-id="<?= (int) $game['id'] ?>">
                    <td data-col="title">
                        <span class="game-cell">
                            <?php if ($game['thumb_path']): ?>
                                <img class="picker-cover" alt="" loading="lazy"
                                     src="<?= e(GameImages::publicUrl($game['thumb_path'], (string) $game['thumb_version'])) ?>">
                            <?php else: ?>
                                <span class="picker-cover"></span>
                            <?php endif; ?>
                            <span class="game-title"><?= e($game['title']) ?></span>
                        </span>
                    </td>
                    <td data-col="publisher"><?= e($game['publisher_name'] ?: '—') ?></td>
                    <td data-col="developer"><?= e($game['developer_name'] ?: '—') ?></td>
                    <td class="release-cell" data-col="release">
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
                    <td data-col="source">
                        <?php if ($game['source_provider']): ?>
                            <?= e(code_label('api_provider', $game['source_provider'])) ?>
                            <?php if ($game['source_synced_at']): ?>
                                <small class="muted block"><?= e(fmt_date($game['source_synced_at'])) ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-col="keys"><?= (int) $game['my_keys'] ?></td>
                    <td class="rowactions">
                        <?php if ($game['source_provider']): ?>
                            <button type="button" class="btn small game-refresh" data-id="<?= (int) $game['id'] ?>"
                                    title="<?= e(__('ui.action.refresh_hint')) ?>">
                                <?= e(__('ui.action.refresh')) ?>
                            </button>
                        <?php else: ?>
                            <span class="muted small"><?= e(__('ui.label.manual_entry')) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
