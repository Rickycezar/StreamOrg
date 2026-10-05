<?php
/** @var array $keys @var array $counts @var array $filters @var array $statuses
 *  @var array $platforms @var array $sources
 *  @var bool $canAddGames @var bool $vaultLocked */
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.vault')) ?></h1>
    <?php require dirname(__DIR__) . '/partials/vault_security_link.php'; ?>
</div>

<?php if ($vaultLocked): ?>
    <?php $back = '/keys'; require dirname(__DIR__) . '/partials/vault_unlock.php'; ?>
<?php endif; ?>

<section class="tiles compact">
    <?php foreach ($statuses as $status): ?>
        <a class="tile <?= $filters['status'] === $status ? 'active' : '' ?>"
           href="<?= e(url('/keys?status=' . $status)) ?>">
            <span class="tile-value"><?= (int) ($counts[$status] ?? 0) ?></span>
            <span class="tile-label"><?= e(code_label('key_status', $status)) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<form class="card filters" method="get" action="<?= e(url('/keys')) ?>">
    <label>
        <span><?= e(__('ui.action.search')) ?></span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(__('ui.field.game')) ?>">
    </label>

    <label>
        <span><?= e(__('ui.field.status')) ?></span>
        <select name="status">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                    <?= e(code_label('key_status', $status)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        <span><?= e(__('ui.field.key_type')) ?></span>
        <select name="key_type">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <?php foreach (['common', 'review'] as $type): ?>
                <option value="<?= e($type) ?>" <?= $filters['key_type'] === $type ? 'selected' : '' ?>>
                    <?= e(code_label('key_type', $type)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        <span><?= e(__('ui.field.content_type')) ?></span>
        <select name="content_type">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <?php foreach (['game', 'dlc'] as $type): ?>
                <option value="<?= e($type) ?>" <?= $filters['content_type'] === $type ? 'selected' : '' ?>>
                    <?= e(code_label('content_type', $type)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        <span><?= e(__('ui.field.platform')) ?></span>
        <select name="platform">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <?php foreach ($platforms as $platform): ?>
                <option value="<?= e($platform['code']) ?>" <?= $filters['platform'] === $platform['code'] ? 'selected' : '' ?>>
                    <?= e(code_label('game_platform', $platform['code'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        <span><?= e(__('ui.field.source')) ?></span>
        <select name="source">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <?php foreach ($sources as $source): ?>
                <option value="<?= e($source) ?>" <?= $filters['source'] === $source ? 'selected' : '' ?>>
                    <?= e(code_label('key_platform', $source)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <div class="filter-actions">
        <button type="submit" class="btn"><?= e(__('ui.action.filter')) ?></button>
        <a class="btn ghost" href="<?= e(url('/keys')) ?>"><?= e(__('ui.action.clear')) ?></a>
    </div>
</form>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.keys_shown')) ?> (<?= count($keys) ?>)</h2>
        <button type="button" class="btn primary" data-new data-modal-form="#add-key"
                data-modal-title="<?= e(__('ui.action.add_key')) ?>"><?= e(__('ui.action.add_key')) ?></button>
    </div>

    <form id="add-key" class="subform hidden" method="post" action="<?= e(url('/keys')) ?>" autocomplete="off">
        <?= Csrf::field() ?>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.game')) ?></span>
                <select name="game_id" id="key-game" required data-picker="games">
                    <option value=""></option>
                </select>
            </label>

            <label>
                <span><?= e(__('ui.field.platform')) ?></span>
                <select name="game_platform" required>
                    <?php foreach ($platforms as $platform): ?>
                        <option value="<?= e($platform['code']) ?>" <?= $platform['code'] === 'pc_steam' ? 'selected' : '' ?>>
                            <?= e(code_label('game_platform', $platform['code'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span><?= e(__('ui.field.source')) ?></span>
                <select name="key_platform" required>
                    <?php foreach ($sources as $source): ?>
                        <option value="<?= e($source) ?>"><?= e(code_label('key_platform', $source)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span><?= e(__('ui.field.key_type')) ?></span>
                <select name="key_type">
                    <?php foreach (['common', 'review'] as $type): ?>
                        <option value="<?= e($type) ?>"><?= e(code_label('key_type', $type)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span><?= e(__('ui.field.content_type')) ?></span>
                <select name="content_type">
                    <?php foreach (['game', 'dlc'] as $type): ?>
                        <option value="<?= e($type) ?>"><?= e(code_label('content_type', $type)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span><?= e(__('ui.field.status')) ?></span>
                <select name="status">
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>"><?= e(code_label('key_status', $status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <?php if ($canAddGames): ?>
        <div class="inline-actions">
            <button type="button" class="btn small" data-toggle="#key-inline-game">+ <?= e(__('ui.action.new_game_api')) ?></button>
        </div>

        <fieldset id="key-inline-game" class="inset hidden" data-game-import="#key-game">
            <legend><?= e(__('ui.action.new_game_api')) ?></legend>
            <label>
                <span><?= e(__('ui.action.search')) ?></span>
                <input type="search" class="game-import-search" autocomplete="off"
                       placeholder="<?= e(__(Twitch::isConfigured() ? 'ui.label.search_twitch_hint' : 'ui.label.search_hint')) ?>">
            </label>
            <div class="results game-import-results"></div>
        </fieldset>
        <?php endif; ?>

        <div class="grid">
            <label>
                <span><?= e(__('ui.field.redeem_by')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                <input type="datetime-local" name="expires_at">
            </label>
        </div>

        <label>
            <span><?= e(__('ui.field.key_codes')) ?></span>
            <textarea name="key_code" rows="4" required class="key-area"
                      autocomplete="off" spellcheck="false"
                      placeholder="<?= e(__('ui.label.one_per_line')) ?>"></textarea>
        </label>

        <label>
            <span><?= e(__('ui.field.notes')) ?></span>
            <input type="text" name="notes">
        </label>

        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>

    <?php if ($keys === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="keys" data-table="keys">
            <thead>
            <tr>
                <th data-col="game"><?= e(__('ui.field.game')) ?></th>
                <th data-col="platform"><?= e(__('ui.field.platform')) ?></th>
                <th data-col="source"><?= e(__('ui.field.source')) ?></th>
                <th data-col="key_type"><?= e(__('ui.field.key_type')) ?></th>
                <th data-col="content_type"><?= e(__('ui.field.content_type')) ?></th>
                <th data-col="redeem_by"><?= e(__('ui.field.redeem_by')) ?></th>
                <th data-col="status"><?= e(__('ui.field.status')) ?></th>
                <th data-col="key_code"><?= e(__('ui.field.key_code')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($keys as $key): ?>
                <tr data-key-id="<?= (int) $key['id'] ?>">
                    <td data-col="game">
                        <?= e($key['game_title']) ?>
                        <?php if ($key['under_embargo']): ?>
                            <span class="badge danger"
                                  title="<?= e(sprintf(__('ui.label.under_embargo_until'), fmt_datetime($key['embargo_until']))) ?>">
                                <?= e(__('ui.label.embargoed_short')) ?> <?= e(fmt_date($key['embargo_until'])) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($key['playable_early']): ?>
                            <span class="badge early" title="<?= e(__('ui.label.playable_early_hint')) ?>">
                                <?= e(__('ui.label.pre_release')) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($key['notes'])): ?>
                            <small class="muted block"><?= e($key['notes']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td data-col="platform"><?= e(code_label('game_platform', $key['game_platform_code'])) ?></td>
                    <td data-col="source"><?= e(code_label('key_platform', $key['key_platform_code'])) ?></td>
                    <td data-col="key_type"><?= e(code_label('key_type', $key['key_type'])) ?></td>
                    <td data-col="content_type"><?= e(code_label('content_type', $key['content_type'])) ?></td>
                    <td class="dates" data-col="redeem_by">
                        <?php if (!empty($key['expires_at'])): ?>
                            <small class="muted" title="<?= e(__('ui.label.redeem_by_hint')) ?>">
                                <?= e(__('ui.label.redeem_by')) ?> <?= e(fmt_date($key['expires_at'])) ?>
                            </small>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-col="status">
                        <select class="status-select status-<?= e($key['status']) ?>" data-id="<?= (int) $key['id'] ?>"
                                <?= $key['under_embargo'] ? 'data-embargo="' . e(fmt_datetime($key['embargo_until'])) . '" data-game="' . e($key['game_title']) . '"' : '' ?>>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status) ?>" <?= $key['status'] === $status ? 'selected' : '' ?>>
                                    <?= e(code_label('key_status', $status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="keycell" data-col="key_code">
                        <div class="keyfield">
                            <input type="text" class="key-field masked" readonly
                                   data-id="<?= (int) $key['id'] ?>"
                                   autocomplete="off" spellcheck="false"
                                   placeholder="••••••••••••">
                            <button type="button" class="btn small key-load" data-id="<?= (int) $key['id'] ?>"
                                    title="<?= e(__('ui.action.load_key')) ?>"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.4 12a8.4 8.4 0 1 1-2.5-5.9"/><path d="M20.4 3.6v5h-5"/></svg></button>
                            <button type="button" class="btn small key-peek"
                                    title="<?= e(__('ui.action.peek_hint')) ?>"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1.6 12S5.4 5.2 12 5.2 22.4 12 22.4 12 18.6 18.8 12 18.8 1.6 12 1.6 12Z"/><circle cx="12" cy="12" r="3.1"/></svg></button>
                            <button type="button" class="btn small key-copy"
                                    title="<?= e(__('ui.action.copy')) ?>"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11.5" height="11.5" rx="2.2"/><path d="M5.5 15.5A2 2 0 0 1 3.5 13.5v-8a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2"/></svg></button>
                        </div>
                    </td>
                    <td class="rowactions">
                        <button type="button" class="btn small key-detail" data-id="<?= (int) $key['id'] ?>">
                            <?= e(__('ui.action.details')) ?>
                        </button>
                        <button type="button" class="btn small" data-modal-form="#edit-key-<?= (int) $key['id'] ?>"
                                data-modal-title="<?= e($key['game_title']) ?>">
                            <?= e(__('ui.action.edit')) ?>
                        </button>
                    </td>
                </tr>

                <tr id="edit-key-<?= (int) $key['id'] ?>" class="editrow hidden"
                    data-edit-url="<?= e(url('/keys/edit?id=' . (int) $key['id'])) ?>">
                    <td colspan="9"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
