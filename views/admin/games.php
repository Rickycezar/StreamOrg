<?php
/**
 * Administration → Games: the shared catalogue, adding games, their Twitch
 * categories, and turning games added by hand into Steam ones.
 *
 * @var array $games @var bool $twitchReady @var bool $steamReady @var int $missing
 * @var int $manual games added by hand @var bool $manualOnly whether only those are listed
 */
?>
<h1><?= e(__('ui.nav.games')) ?></h1>
<p class="muted">
    <?= e(__('ui.message.games_intro')) ?>
    <a href="<?= e(url('/admin/import')) ?>"><?= e(__('ui.action.import_instead')) ?></a>
</p>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.games')) ?> (<?= count($games) ?>)</h2>
        <div class="card-actions">
            <?php if ($manual > 0 || $manualOnly): ?>
                <a class="btn<?= $manualOnly ? ' primary' : '' ?>" href="<?= e(url($manualOnly ? '/admin/games' : '/admin/games?source=manual')) ?>">
                    <?= e(sprintf(__('ui.action.show_manual_games'), $manual)) ?>
                </a>
            <?php endif; ?>
            <?php if ($steamReady && $manual > 0): ?>
                <form method="post" action="<?= e(url('/admin/games/convert')) ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn" title="<?= e(__('ui.message.convert_games_hint')) ?>"
                            data-confirm="<?= e(__('ui.message.convert_games_confirm')) ?>"><?= e(sprintf(__('ui.action.convert_games'), $manual)) ?></button>
                </form>
            <?php endif; ?>
            <?php if ($twitchReady && $missing > 0): ?>
                <form method="post" action="<?= e(url('/admin/games/categories')) ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn" title="<?= e(__('ui.message.twitch_categories_hint')) ?>">
                        <?= e(sprintf(__('ui.action.find_twitch_categories'), $missing)) ?>
                    </button>
                </form>
            <?php endif; ?>
            <button type="button" class="btn primary" data-modal-form="#add-game"
                    data-modal-title="<?= e(__('ui.action.create')) ?>"><?= e(__('ui.action.create')) ?></button>
        </div>
    </div>

<form id="add-game" method="post" action="<?= e(url('/admin/games')) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <?php if ($twitchReady): ?>
            <label>
                <span><?= e(__('ui.field.twitch_category')) ?></span>
                <select name="category_id" data-picker="categories" data-placeholder="<?= e(__('ui.label.picker_search')) ?>">
                    <option value=""></option>
                </select>
            </label>
            <p class="muted small"><?= e(__('ui.message.add_game_admin_hint')) ?></p>
        <?php endif; ?>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.title')) ?></span>
                <input type="text" name="title" <?= $twitchReady ? '' : 'required' ?>>
            </label>
            <label>
                <span><?= e(__('ui.nav.publishers')) ?></span>
                <select name="publisher_id" data-picker="publishers">
                    <option value=""></option>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.nav.developers')) ?></span>
                <select name="developer_id" data-picker="developers">
                    <option value=""></option>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.release_date')) ?></span>
                <input type="date" name="release_date">
            </label>
            <label>
                <span><?= e(__('ui.field.store_url')) ?></span>
                <input type="url" name="store_url" placeholder="https://">
            </label>
        </div>
        <label>
            <span><?= e(__('ui.field.description')) ?></span>
            <textarea name="description" rows="2"></textarea>
        </label>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>

    <?php if ($games === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th><?= e(__('ui.field.title')) ?></th>
                <th><?= e(__('ui.nav.publishers')) ?></th>
                <th><?= e(__('ui.nav.developers')) ?></th>
                <th><?= e(__('ui.field.release_date')) ?></th>
                <th><?= e(__('ui.field.source')) ?></th>
                <th><?= e(__('ui.field.twitch_category')) ?></th>
                <th><?= e(__('ui.field.game_hashtag')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($games as $game): ?>
                <tr<?= row_art($game['art_path'], $game['art_version']) ?>>
                    <td data-card-title data-label="<?= e(__('ui.field.title')) ?>">
                        <?php if (safe_url($game['store_url'])): ?>
                            <a href="<?= e(safe_url($game['store_url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($game['title']) ?></a>
                        <?php else: ?>
                            <?= e($game['title']) ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= e(__('ui.nav.publishers')) ?>"><?= e($game['publisher_name'] ?: '—') ?></td>
                    <td data-label="<?= e(__('ui.nav.developers')) ?>"><?= e($game['developer_name'] ?: '—') ?></td>
                    <td data-label="<?= e(__('ui.field.release_date')) ?>"><?= e(fmt_date($game['release_date'])) ?></td>
                    <td data-label="<?= e(__('ui.field.source')) ?>"><?= $game['source_provider'] ? e(code_label('api_provider', $game['source_provider'])) : '<span class="badge warn">' . e(__('ui.label.by_hand')) . '</span>' ?></td>
                    <td data-label="<?= e(__('ui.field.twitch_category')) ?>">
                        <?php if ($game['twitch_category_source'] === TwitchCategories::DEFAULT_SOURCE): ?>
                            <span class="badge" title="<?= e(__('ui.message.twitch_category_default_hint')) ?>"><?= e($game['twitch_category_name']) ?> · <?= e(__('ui.label.default')) ?></span>
                        <?php else: ?>
                            <span class="badge twitch-cat"><?= e($game['twitch_category_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= e(__('ui.field.game_hashtag')) ?>">
                        <?php if ($game['hashtag']): ?>
                            <code><?= e($game['hashtag']) ?></code>
                        <?php else: ?>
                            <?php $guess = GameHashtag::suggest((string) $game['title']); ?>
                            <form method="post" action="<?= e(url('/admin/games/hashtag')) ?>" class="hashtag-confirm">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="game_id" value="<?= (int) $game['id'] ?>">
                                <input type="hidden" name="hashtag" value="<?= e($guess) ?>">
                                <span class="muted" title="<?= e(__('ui.label.hashtag_guess_hint')) ?>"><?= e($guess) ?> · <?= e(__('ui.label.guess')) ?></span>
                                <button type="submit" class="btn small" title="<?= e(__('ui.label.hashtag_confirm_hint')) ?>"><?= e(__('ui.action.confirm')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td class="rowactions">
                        <?php if ($steamReady && !$game['source_provider']): ?>
                            <button type="button" class="btn small primary" data-modal-form="#steam-<?= (int) $game['id'] ?>"
                                    data-modal-title="<?= e($game['title']) ?>"><?= icon_label('search', __('ui.action.find_on_steam')) ?></button>
                        <?php endif; ?>
                        <?php if ($twitchReady): ?>
                            <button type="button" class="btn small" data-modal-form="#cat-<?= (int) $game['id'] ?>"
                                    data-modal-title="<?= e($game['title']) ?>"><?= icon_label('twitch', __('ui.field.twitch_category')) ?></button>
                        <?php endif; ?>
                        <button type="button" class="btn small" data-modal-form="#tag-<?= (int) $game['id'] ?>"
                                data-modal-title="<?= e($game['title']) ?>"><?= icon_label('hash', __('ui.field.game_hashtag')) ?></button>
                        <button type="button" class="btn small danger-btn row-delete"
                                data-endpoint="/admin/catalogue/delete" data-kind="game"
                                data-id="<?= (int) $game['id'] ?>" data-label="<?= e($game['title']) ?>">
                            <?= icon_label('trash', __('ui.action.delete')) ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($steamReady): foreach ($games as $game): if ($game['source_provider']) { continue; } ?>
    <form id="steam-<?= (int) $game['id'] ?>" method="post" action="<?= e(url('/admin/games/steam')) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <input type="hidden" name="game_id" value="<?= (int) $game['id'] ?>">
        <p class="muted small"><?= e(__('ui.message.find_on_steam_explain')) ?></p>
        <label>
            <span><?= e(__('ui.label.steam_game')) ?></span>
            <select name="ref" required data-picker="steam" data-placeholder="<?= e(__('ui.label.picker_search')) ?>"
                    data-query="<?= e(trim((string) preg_replace('/\s*\((?:19|20)\d{2}\)\s*$/', '', (string) $game['title']))) ?>">
                <option value=""></option>
            </select>
        </label>
        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__('ui.action.use_steam_game')) ?></button>
            <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
        </div>
    </form>
<?php endforeach; endif; ?>

<?php if ($twitchReady): foreach ($games as $game): ?>
    <form id="cat-<?= (int) $game['id'] ?>" method="post" action="<?= e(url('/admin/games/category')) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <input type="hidden" name="game_id" value="<?= (int) $game['id'] ?>">
        <p class="muted small"><?= e(__('ui.message.twitch_category_explain')) ?></p>
        <label>
            <span><?= e(__('ui.field.twitch_category')) ?></span>
            <select name="category_id" data-picker="categories" data-placeholder="<?= e(__('ui.label.picker_search')) ?>">
                <option value=""></option>
                <?php if ($game['twitch_category_source'] !== TwitchCategories::DEFAULT_SOURCE): ?>
                    <option value="<?= e($game['twitch_category_id']) ?>" selected><?= e($game['twitch_category_name']) ?></option>
                <?php endif; ?>
            </select>
        </label>
        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
            <button type="submit" name="find" value="1" class="btn"><?= e(__('ui.action.find_automatically')) ?></button>
            <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
        </div>
    </form>
<?php endforeach; endif; ?>

<?php foreach ($games as $game): $guess = GameHashtag::suggest((string) $game['title']); ?>
    <form id="tag-<?= (int) $game['id'] ?>" method="post" action="<?= e(url('/admin/games/hashtag')) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <input type="hidden" name="game_id" value="<?= (int) $game['id'] ?>">
        <p class="muted small"><?= e(__('ui.message.game_hashtag_explain')) ?></p>
        <label>
            <span><?= e(__('ui.field.game_hashtag')) ?></span>
            <input type="text" name="hashtag" value="<?= e($game['hashtag'] ?? '') ?>" placeholder="<?= e($guess) ?>"
                   maxlength="81" pattern="#?[A-Za-z0-9_]{1,80}" autocomplete="off" spellcheck="false">
            <small class="muted"><?= e(sprintf(__('ui.label.hashtag_guess'), $guess)) ?></small>
        </label>
        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
            <button type="button" class="btn" data-fill-from-placeholder><?= e(__('ui.action.use_guess')) ?></button>
            <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
        </div>
    </form>
<?php endforeach; ?>
