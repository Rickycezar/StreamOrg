<?php
/**
 * One giveaway: its rules, prizes, winners and chat entries.
 *
 * @var array $giveaway @var array $prizes @var array $available @var array $winners
 * @var array $entries @var array $content @var bool $vaultLocked @var bool $twitchReady @var bool $needsCopies
 * @var array{waiting:int, locked:int} $waitingWinners @var bool $nudge @var bool $takeBackWarn
 */
$g       = $giveaway;
$id      = (int) $g['id'];
$open    = !in_array($g['status'], Giveaways::ENDED, true);
$unclaimed = array_values(array_filter($prizes, static fn (array $p): bool => $p['claimed_at'] === null));
$ready     = count(array_filter($unclaimed, static fn (array $p): bool => (bool) $p['ready']));
$pendingWinners = count(array_filter($winners, static fn (array $w): bool => $w['state'] === 'waiting'));
$waiting = array_values(array_filter($prizes, static fn (array $p): bool => $p['claimed_at'] === null && $p['winner_id'] === null));
$next    = GiveawayController::NEXT[$g['status']] ?? [];
?>
<p class="muted small"><a href="<?= e(url('/giveaways')) ?>">← <?= e(__('ui.nav.giveaways')) ?></a></p>

<div class="page-head">
    <h1>
        <?= e($g['title']) ?>
        <span class="badge giveaway-status-<?= e($g['status']) ?>"><?= e(code_label('giveaway_status', $g['status'])) ?></span>
        <?php if ($g['is_surprise']): ?><span class="badge"><?= e(__('ui.label.surprise')) ?></span><?php endif; ?>
    </h1>
    <div class="card-actions page-actions">
        <?php foreach ($next as $status): ?>
            <form method="post" action="<?= e(url('/giveaways/status')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="status" value="<?= e($status) ?>">
                <button type="submit" class="btn<?= in_array($status, ['open', 'finished'], true) ? ' primary' : '' ?><?= $status === 'cancelled' ? ' ghost' : '' ?>"
                    <?= in_array($status, Giveaways::ENDED, true) ? 'data-confirm="' . e(__('ui.message.giveaway_finish_confirm')) . '"' : '' ?>>
                    <?= e(__('ui.action.giveaway_' . $status)) ?>
                </button>
            </form>
        <?php endforeach; ?>
        <button type="button" class="btn" data-modal-form="#edit-giveaway" data-modal-title="<?= e($g['title']) ?>"><?= e(__('ui.action.edit')) ?></button>
    </div>
</div>

<?php $formId = 'edit-giveaway'; require __DIR__ . '/form.php'; ?>

<?php if ($takeBackWarn): ?>
    <section class="nudge nudge-warn">
        <span class="nudge-icon" aria-hidden="true">🎁</span>
        <div>
            <h2><?= e(__('ui.message.takeback_warn_title')) ?></h2>
            <p><?= e(sprintf(__('ui.message.takeback_warn_text'), $waitingWinners['waiting'])) ?></p>
            <div class="card-actions">
                <form method="post" action="<?= e(url('/giveaways/keep-open')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn primary"><?= e(__('ui.action.takeback_wait')) ?></button>
                </form>
                <form method="post" action="<?= e(url('/giveaways/take-back')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="confirm" value="1">
                    <button type="submit" class="btn ghost"><?= e(__('ui.action.takeback_anyway')) ?></button>
                </form>
            </div>
        </div>
    </section>
<?php elseif ($nudge && $open): ?>
    <section class="nudge">
        <span class="nudge-icon" aria-hidden="true">🚪</span>
        <div>
            <h2><?= e(__('ui.message.nudge_title')) ?></h2>
            <p><?= e(sprintf(__('ui.message.nudge_text'), $waitingWinners['locked'])) ?></p>
            <?php if ($vaultLocked): ?>
                <?php $back = '/giveaways/show?id=' . $id; require dirname(__DIR__) . '/partials/vault_unlock.php'; ?>
            <?php endif; ?>
            <div class="card-actions">
                <?php if (!$vaultLocked): ?>
                    <form method="post" action="<?= e(url('/giveaways/claimable')) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button type="submit" class="btn primary"><?= e(__('ui.action.nudge_open')) ?></button>
                    </form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/giveaways/nudge-later')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn ghost"><?= e(__('ui.action.nudge_later')) ?></button>
                </form>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="card giveaway-summary">
    <dl>
        <div><dt><?= e(__('ui.field.keyword')) ?></dt><dd><code>!<?= e(__('ui.label.join_command')) ?> <?= e((string) $g['keyword']) ?></code></dd></div>
        <div><dt><?= e(__('ui.field.entry_method')) ?></dt><dd><?= e(code_label('entry_method', $g['entry_method'])) ?></dd></div>
        <div><dt><?= e(__('ui.field.winner_mode')) ?></dt><dd><?= e(code_label('winner_mode', $g['winner_mode'])) ?></dd></div>
        <div><dt><?= e(__('ui.field.entries_open')) ?></dt><dd><?= e($g['starts_at'] ? fmt_datetime($g['starts_at']) : '—') ?></dd></div>
        <div><dt><?= e(__('ui.field.entries_close')) ?></dt><dd><?= e($g['ends_at'] ? fmt_datetime($g['ends_at']) : '—') ?></dd></div>
        <div><dt><?= e(__('ui.field.claim_days')) ?></dt><dd><?= (int) $g['claim_days'] ?></dd></div>
    </dl>
    <?php if ($g['rules_note']): ?>
        <p class="giveaway-rules"><?= nl2br(e($g['rules_note'])) ?></p>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.prizes')) ?> (<?= count($prizes) ?>)</h2>
        <?php if ($open && $available !== []): ?>
            <button type="button" class="btn small" data-modal-form="#add-prizes" data-modal-title="<?= e(__('ui.action.add_prizes')) ?>">+ <?= e(__('ui.action.add_prizes')) ?></button>
        <?php endif; ?>
    </div>

    <?php if ($prizes !== []): ?>
        <div class="prize-safety <?= $needsCopies && $ready < count($unclaimed) ? 'pending' : '' ?>">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4"/></svg>
            <div>
                <?php if (!$needsCopies): ?>
                    <p><?= e(__('ui.message.prizes_safe_recoverable')) ?></p>
                <?php elseif ($unclaimed === []): ?>
                    <p><?= e(__('ui.message.prizes_safe_none_left')) ?></p>
                <?php elseif ($ready === 0): ?>
                    <p><?= e(__('ui.message.prizes_safe_private')) ?></p>
                <?php else: ?>
                    <p><?= e(sprintf(__('ui.message.prizes_safe_claimable'), $ready, count($unclaimed))) ?></p>
                <?php endif; ?>
                <?php if ($needsCopies && $open && $unclaimed !== []): ?>
                    <?php if ($vaultLocked && $ready < count($unclaimed)): ?>
                        <?php $back = '/giveaways/show?id=' . $id; require dirname(__DIR__) . '/partials/vault_unlock.php'; ?>
                    <?php else: ?>
                        <div class="card-actions">
                            <?php if ($ready < count($unclaimed)): ?>
                                <form method="post" action="<?= e(url('/giveaways/claimable')) ?>">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button type="submit" class="btn primary small"><?= e(__('ui.action.make_claimable')) ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($ready > 0): ?>
                                <form method="post" action="<?= e(url('/giveaways/take-back')) ?>">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button type="submit" class="btn small"><?= e(__('ui.action.take_back')) ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <form id="add-prizes" method="post" action="<?= e(url('/giveaways/prizes')) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <p class="muted small"><?= e(__('ui.message.giveaway_prizes_explain')) ?></p>
        <div class="prize-pick">
            <?php foreach ($available as $key): ?>
                <label class="inline prize-option">
                    <input type="checkbox" name="keys[]" value="<?= (int) $key['id'] ?>" <?= $key['is_placeholder'] ? 'disabled' : '' ?>>
                    <span>
                        <?= e($key['title']) ?>
                        <small class="muted"><?= e(code_label('game_platform', $key['platform_code'])) ?><?= $key['expires_at'] ? ' · ' . e(sprintf(__('ui.label.redeem_by_date'), fmt_date($key['expires_at']))) : '' ?><?= $key['is_placeholder'] ? ' · ' . e(__('ui.label.placeholder_key')) : '' ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__('ui.action.add_prizes')) ?></button>
            <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
        </div>
    </form>

    <?php if ($prizes === []): ?>
        <p class="empty"><?= e($available === [] ? __('ui.message.giveaway_no_keys_for_giveaway') : __('ui.message.giveaway_no_prizes')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table data-sortable>
                <thead>
                <tr>
                    <th class="col-actions"><span class="visually-hidden"><?= e(__('ui.label.actions')) ?></span></th>
                    <th><?= e(__('ui.field.game')) ?></th>
                    <th><?= e(__('ui.field.platform')) ?></th>
                    <th><?= e(__('ui.field.redeem_by')) ?></th>
                    <th><?= e(__('ui.field.status')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($prizes as $p): ?>
                    <tr>
                        <td class="rowactions">
                            <?php if (!$p['claimed_at']): ?>
                                <form method="post" action="<?= e(url('/giveaways/prizes/remove')) ?>">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="prize_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="btn small"><?= e(__('ui.action.remove')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><?= e($p['game_title']) ?></td>
                        <td><?= e(code_label('game_platform', $p['platform_code'])) ?></td>
                        <td class="nowrap" data-sort="<?= e(sort_key($p['expires_at'])) ?>"><?= e($p['expires_at'] ? fmt_date($p['expires_at']) : '—') ?></td>
                        <td>
                            <?php if ($p['claimed_at']): ?>
                                <span class="badge ok"><?= e(sprintf(__('ui.label.claimed_by'), $p['winner_login'])) ?></span>
                                <small class="muted"><?= e(fmt_datetime($p['claimed_at'], 'd/m H:i')) ?></small>
                            <?php elseif ($p['winner_id']): ?>
                                <span class="badge early"><?= e(sprintf(__('ui.label.set_aside_for'), $p['winner_login'])) ?></span>
                            <?php else: ?>
                                <span class="badge"><?= e(__('ui.label.waiting_for_winner')) ?></span>
                            <?php endif; ?>
                            <?php if ($needsCopies && !$p['claimed_at']): ?>
                                <small class="muted"><?= e(__($p['ready'] ? 'ui.label.prize_claimable' : 'ui.label.prize_in_vault')) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.winners')) ?> (<?= count(array_filter($winners, static fn ($w) => $w['state'] !== 'cancelled')) ?>)</h2>
        <div class="card-actions">
            <?php if ($g['entry_method'] === 'chat' && $entries !== [] && $g['status'] !== 'cancelled'): ?>
                <form method="post" action="<?= e(url('/giveaways/winners/draw')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn primary"><?= e(__('ui.action.draw_winner')) ?></button>
                </form>
            <?php endif; ?>
            <?php if ($g['status'] !== 'cancelled' && $twitchReady): ?>
                <button type="button" class="btn" data-modal-form="#add-winner" data-modal-title="<?= e(__('ui.action.add_winner')) ?>">+ <?= e(__('ui.action.add_winner')) ?></button>
            <?php endif; ?>
        </div>
    </div>

    <form id="add-winner" method="post" action="<?= e(url('/giveaways/winners')) ?>" class="subform hidden" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="grid">
            <label class="grow">
                <span><?= e(__('ui.field.twitch_user')) ?></span>
                <input type="text" name="login" required pattern="@?[A-Za-z0-9_]{3,25}" placeholder="@nick" spellcheck="false">
                <small class="muted"><?= e(__('ui.label.twitch_user_hint')) ?></small>
            </label>
            <label>
                <span><?= e(__('ui.field.winner_method')) ?></span>
                <select name="method">
                    <?php foreach (['external', 'manual'] as $method): ?>
                        <option value="<?= e($method) ?>" <?= $g['entry_method'] === $method ? 'selected' : '' ?>><?= e(code_label('winner_method', $method)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($g['winner_mode'] === 'assigned'): ?>
                <label class="grow">
                    <span><?= e(__('ui.field.prize')) ?></span>
                    <select name="prize_id" required>
                        <?php foreach ($waiting as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"><?= e($p['game_title'] . ' · ' . code_label('game_platform', $p['platform_code'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
        </div>
        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__('ui.action.add_winner')) ?></button>
            <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
        </div>
    </form>

    <?php if ($winners === []): ?>
        <p class="empty"><?= e(__('ui.message.giveaway_no_winners')) ?></p>
    <?php else: ?>
        <p class="muted small"><?= e(__('ui.message.claim_link_explain')) ?></p>
        <ul class="winner-list">
            <?php foreach ($winners as $w): ?>
                <li class="state-<?= e($w['state']) ?>">
                    <div class="winner-who">
                        <a href="https://twitch.tv/<?= e(rawurlencode($w['twitch_login'])) ?>" target="_blank" rel="noopener noreferrer"><strong><?= e($w['display_name'] ?: $w['twitch_login']) ?></strong></a>
                        <small class="muted"><?= e(code_label('winner_method', $w['method'])) ?> · <?= e(fmt_datetime($w['created_at'], 'd/m H:i')) ?></small>
                    </div>
                    <div class="winner-state">
                        <span class="badge winner-<?= e($w['state']) ?>"><?= e(__('ui.label.winner_state_' . $w['state'])) ?></span>
                        <?php if ($w['state'] === 'waiting'): ?>
                            <small class="muted"><?= e(sprintf(__('ui.label.until_date'), fmt_datetime($w['expires_at'], 'd/m H:i'))) ?></small>
                        <?php endif; ?>
                        <?php if ($w['prize_title']): ?><small class="muted"><?= e($w['prize_title']) ?></small><?php endif; ?>
                        <?php if ($w['state'] === 'removed' && $w['removed_reason']): ?><small class="muted winner-removed-reason"><?= e($w['removed_reason']) ?></small><?php endif; ?>
                    </div>
                    <?php if (in_array($w['state'], ['waiting', 'expired'], true) && $w['link']): ?>
                        <div class="winner-link">
                            <input type="text" class="copy-field" id="claim-<?= (int) $w['id'] ?>" value="<?= e($w['link']) ?>" readonly aria-label="<?= e(__('ui.label.claim_link')) ?>">
                            <button type="button" class="btn small" data-copy="#claim-<?= (int) $w['id'] ?>"><?= e(__('ui.action.copy')) ?></button>
                        </div>
                        <div class="winner-actions">
                            <?php if ($w['state'] === 'expired'): ?>
                                <form method="post" action="<?= e(url('/giveaways/winners/extend')) ?>">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="winner_id" value="<?= (int) $w['id'] ?>">
                                    <button type="submit" class="btn small"><?= e(__('ui.action.extend_claim')) ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url('/giveaways/winners/cancel')) ?>">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="winner_id" value="<?= (int) $w['id'] ?>">
                                <button type="submit" class="btn small ghost" data-confirm="<?= e(__('ui.message.winner_cancel_confirm')) ?>"><?= e(__('ui.action.withdraw')) ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($g['entry_method'] === 'chat'): ?>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.entries')) ?> (<?= count($entries) ?>)</h2>
    </div>
    <p class="muted small"><?= e(sprintf(__('ui.message.giveaway_entries_explain'), (string) $g['keyword'])) ?></p>
    <?php if ($entries === []): ?>
        <p class="empty"><?= e(__('ui.message.giveaway_no_entries')) ?></p>
    <?php else: ?>
        <ul class="entry-list">
            <?php foreach ($entries as $entry): ?>
                <li><?= e($entry['display_name'] ?: $entry['twitch_login']) ?> <small class="muted"><?= e(fmt_datetime($entry['entered_at'], 'd/m H:i')) ?></small></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($g['status'] === 'closed' && $pendingWinners === 0 && $unclaimed !== []): ?>
    <div class="flash flash-success finish-hint"><?= e(sprintf(__('ui.message.giveaway_finish_hint'), count($unclaimed))) ?></div>
<?php endif; ?>

<?php if ($g['status'] !== 'open'): ?>
<form method="post" action="<?= e(url('/giveaways/delete')) ?>" class="danger-zone">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <button type="submit" class="btn small danger-btn" data-confirm="<?= e(__('ui.message.giveaway_delete_confirm')) ?>"><?= e(__('ui.action.delete_giveaway')) ?></button>
</form>
<?php endif; ?>
