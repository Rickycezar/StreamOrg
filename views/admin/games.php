<?php /** @var array $games @var array $publishers @var array $developers @var bool $twitchReady @var int $missing */ ?>
<h1><?= e(__('ui.nav.games')) ?></h1>
<p class="muted">
    <?= e(__('ui.message.games_intro')) ?>
    <a href="<?= e(url('/admin/import')) ?>"><?= e(__('ui.action.import_instead')) ?></a>
</p>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.games')) ?> (<?= count($games) ?>)</h2>
        <div class="card-actions">
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
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($games as $game): ?>
                <tr>
                    <td>
                        <?php if (safe_url($game['store_url'])): ?>
                            <a href="<?= e(safe_url($game['store_url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($game['title']) ?></a>
                        <?php else: ?>
                            <?= e($game['title']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e($game['publisher_name'] ?: '—') ?></td>
                    <td><?= e($game['developer_name'] ?: '—') ?></td>
                    <td><?= e(fmt_date($game['release_date'])) ?></td>
                    <td><?= $game['source_provider'] ? e(code_label('api_provider', $game['source_provider'])) : '—' ?></td>
                    <td>
                        <?php if ($game['twitch_category_source'] === TwitchCategories::DEFAULT_SOURCE): ?>
                            <span class="badge" title="<?= e(__('ui.message.twitch_category_default_hint')) ?>"><?= e($game['twitch_category_name']) ?> · <?= e(__('ui.label.default')) ?></span>
                        <?php else: ?>
                            <span class="badge twitch-cat"><?= e($game['twitch_category_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="rowactions">
                        <?php if ($twitchReady): ?>
                            <button type="button" class="btn small" data-modal-form="#cat-<?= (int) $game['id'] ?>"
                                    data-modal-title="<?= e($game['title']) ?>"><?= e(__('ui.field.twitch_category')) ?></button>
                        <?php endif; ?>
                        <button type="button" class="btn small danger-btn row-delete"
                                data-endpoint="/admin/catalogue/delete" data-kind="game"
                                data-id="<?= (int) $game['id'] ?>" data-label="<?= e($game['title']) ?>">
                            <?= e(__('ui.action.delete')) ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

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
