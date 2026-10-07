<?php
/**
 * Administration → Winners: giveaway winners of every streamer, searchable,
 * with "Remove key" to take a prize back (a reason is required, and for a
 * claimed key, what becomes of it), and the history of removals.
 *
 * @var string $query @var string $state @var list<array> $winners @var list<array> $removals
 */
$removable = static fn (array $w): bool => in_array($w['state'], ['waiting', 'expired', 'claimed'], true);
?>
<h1><?= e(__('ui.nav.winners')) ?></h1>
<p class="muted"><?= e(__('ui.message.winners_admin_intro')) ?></p>

<form class="card filters" method="get" action="<?= e(url('/admin/winners')) ?>">
    <label>
        <span><?= e(__('ui.action.search')) ?></span>
        <input type="search" name="q" value="<?= e($query) ?>" placeholder="<?= e(__('ui.label.winners_search_hint')) ?>">
    </label>
    <label>
        <span><?= e(__('ui.field.status')) ?></span>
        <select name="state">
            <?php foreach (PrizeRemovals::STATES as $s): ?>
                <option value="<?= e($s) ?>" <?= $state === $s ? 'selected' : '' ?>><?= e(__('ui.label.winners_filter_' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="filter-actions">
        <button type="submit" class="btn"><?= e(__('ui.action.filter')) ?></button>
        <a class="btn ghost" href="<?= e(url('/admin/winners')) ?>"><?= e(__('ui.action.clear')) ?></a>
    </div>
</form>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.winners_found')) ?> (<?= count($winners) ?>)</h2>
    </div>

    <?php if ($winners === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="winners-admin">
                <thead>
                <tr>
                    <th><?= e(__('ui.label.winner')) ?></th>
                    <th><?= e(__('ui.label.giveaway')) ?></th>
                    <th><?= e(__('ui.label.prize')) ?></th>
                    <th><?= e(__('ui.field.status')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($winners as $w):
                    $game     = $w['game_title'] ?? $w['removed_game'];
                    $platform = $w['platform_code'] ?? $w['removed_platform']; ?>
                    <tr class="state-<?= e($w['state']) ?>">
                        <td>
                            <a href="https://twitch.tv/<?= e(rawurlencode($w['twitch_login'])) ?>" target="_blank" rel="noopener noreferrer"><strong><?= e($w['display_name'] ?: $w['twitch_login']) ?></strong></a>
                            <small class="muted block">@<?= e($w['twitch_login']) ?> · <?= e(code_label('winner_method', $w['method'])) ?></small>
                        </td>
                        <td>
                            <?= e($w['giveaway_title']) ?>
                            <small class="muted block"><?= e($w['streamer']) ?> · <?= e(code_label('giveaway_status', $w['giveaway_status'])) ?></small>
                        </td>
                        <td>
                            <?php if ($game): ?>
                                <?= e($game) ?>
                                <?php if ($platform): ?><small class="muted block"><?= e(code_label('game_platform', $platform)) ?></small><?php endif; ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge winner-<?= e($w['state']) ?>"><?= e(__('ui.label.winner_state_' . $w['state'])) ?></span>
                            <small class="muted block">
                                <?= e(fmt_datetime($w['removed_at'] ?? $w['claimed_at'] ?? $w['created_at'], 'd/m/Y H:i')) ?>
                                <?php if ($w['state'] === 'removed' && $w['removed_by_name']): ?> · <?= e($w['removed_by_name']) ?><?php endif; ?>
                            </small>
                            <?php if ($w['state'] === 'removed'): ?>
                                <small class="muted block winner-removed-reason"><?= e((string) $w['removed_reason']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="rowactions">
                            <?php if ($removable($w)): ?>
                                <button type="button" class="btn small danger-outline" data-modal-form="#remove-<?= (int) $w['id'] ?>"
                                        data-modal-title="<?= e(sprintf(__('ui.label.remove_key_from'), $w['display_name'] ?: $w['twitch_login'])) ?>"><?= e(__('ui.action.remove_key')) ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($removable($w)): ?>
                        <tr class="hidden">
                            <td colspan="5">
                                <form id="remove-<?= (int) $w['id'] ?>" method="post" action="<?= e(url('/admin/winners/remove')) ?>" class="subform hidden remove-prize">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                    <input type="hidden" name="q" value="<?= e($query) ?>">
                                    <input type="hidden" name="state" value="<?= e($state) ?>">

                                    <dl class="remove-prize-summary">
                                        <dt><?= e(__('ui.label.giveaway')) ?></dt><dd><?= e($w['giveaway_title']) ?> · <?= e($w['streamer']) ?></dd>
                                        <dt><?= e(__('ui.label.prize')) ?></dt><dd><?= e($game ?: '—') ?></dd>
                                        <dt><?= e(__('ui.field.status')) ?></dt><dd><?= e(__('ui.label.winner_state_' . $w['state'])) ?></dd>
                                    </dl>

                                    <?php if ($w['state'] === 'claimed'): ?>
                                        <fieldset class="remove-outcome">
                                            <legend><?= e(__('ui.label.remove_key_outcome')) ?></legend>
                                            <?php foreach (['revoked', 'returned'] as $i => $outcome): ?>
                                                <label class="choice">
                                                    <input type="radio" name="outcome" value="<?= e($outcome) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                                                    <span>
                                                        <strong><?= e(__('ui.label.remove_outcome_' . $outcome)) ?></strong>
                                                        <small class="muted"><?= e(__('ui.message.remove_outcome_' . $outcome)) ?></small>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                    <?php else: ?>
                                        <p class="muted small"><?= e(__($game ? 'ui.message.remove_unclaimed_freed' : 'ui.message.remove_unclaimed_none')) ?></p>
                                    <?php endif; ?>

                                    <label>
                                        <span><?= e(__('ui.field.remove_reason')) ?></span>
                                        <textarea name="reason" rows="3" required maxlength="<?= PrizeRemovals::REASON_MAX ?>"
                                                  placeholder="<?= e(__('ui.label.remove_reason_hint')) ?>"></textarea>
                                        <small class="muted"><?= e(__('ui.message.remove_reason_seen_by')) ?></small>
                                    </label>

                                    <div class="card-actions">
                                        <button type="submit" class="btn danger-btn"><?= e(__('ui.action.remove_key')) ?></button>
                                        <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.removals_recent')) ?></h2>
    </div>
    <?php if ($removals === []): ?>
        <p class="empty"><?= e(__('ui.message.removals_none')) ?></p>
    <?php else: ?>
        <ul class="removal-list">
            <?php foreach ($removals as $r): ?>
                <li>
                    <div>
                        <strong><?= e($r['display_name'] ?: $r['twitch_login']) ?></strong>
                        <span class="muted">· <?= e($r['game_title'] ?: '—') ?> · <?= e($r['giveaway_title']) ?> (<?= e($r['streamer']) ?>)</span>
                        <p class="removal-reason"><?= e($r['reason']) ?></p>
                    </div>
                    <div class="removal-meta">
                        <span class="badge <?= $r['key_outcome'] === 'revoked' ? 'danger' : 'off' ?>"><?= e(__('ui.label.key_outcome_' . $r['key_outcome'])) ?></span>
                        <small class="muted"><?= e(fmt_datetime($r['created_at'], 'd/m/Y H:i')) ?><?= $r['removed_by_name'] ? ' · ' . e($r['removed_by_name']) : '' ?></small>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
