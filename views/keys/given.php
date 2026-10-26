<?php
/**
 * Keys → Given keys: everyone a key was given or promised to, newest
 * first — the key (once decided), the giveaway or direct gift it came
 * from, how they won, when their link was made and when they took the
 * key, and the state. A link not used yet can be copied or withdrawn.
 * Give a key (straight to someone) and New giveaway are on top.
 *
 * @var list<array> $rows @var list<array> $giftable @var bool $twitchReady @var ?int $fresh
 */
$counts = array_count_values(array_column($rows, 'state'));
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.given_keys')) ?></h1>
    <div class="page-actions">
        <?php if ($twitchReady && $giftable !== []): ?>
            <button type="button" class="btn primary" data-modal-form="#give-key" data-modal-title="<?= e(__('ui.action.give_key')) ?>"><?= e(__('ui.action.give_key')) ?></button>
        <?php endif; ?>
        <a class="btn" href="<?= e(url('/giveaways#new')) ?>">+ <?= e(__('ui.action.new_giveaway')) ?></a>
    </div>
</div>
<p class="muted"><?= e(__('ui.message.given_keys_intro')) ?></p>

<section class="tiles compact">
    <?php foreach (['waiting', 'claimed', 'expired', 'cancelled'] as $state): ?>
        <div class="tile">
            <span class="tile-value"><?= (int) ($counts[$state] ?? 0) ?></span>
            <span class="tile-label"><?= e(__('ui.label.winner_state_' . $state)) ?></span>
        </div>
    <?php endforeach; ?>
</section>

<section class="card">
    <?php if ($rows === []): ?>
        <p class="empty"><?= e(__('ui.message.given_keys_none')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table data-sortable data-table="given_keys">
                <thead>
                <tr>
                    <th class="col-actions"><span class="visually-hidden"><?= e(__('ui.label.actions')) ?></span></th>
                    <th data-col="who"><?= e(__('ui.label.beneficiary')) ?></th>
                    <th data-col="key"><?= e(__('ui.field.key')) ?></th>
                    <th data-col="from"><?= e(__('ui.label.given_from')) ?></th>
                    <th data-col="how"><?= e(__('ui.label.given_how')) ?></th>
                    <th data-col="sent"><?= e(__('ui.label.given_sent')) ?></th>
                    <th data-col="taken"><?= e(__('ui.label.given_taken')) ?></th>
                    <th data-col="state"><?= e(__('ui.field.status')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr class="state-<?= e($r['state']) ?><?= $fresh === (int) $r['winner_id'] ? ' just-added' : '' ?>"<?= $r['key_id'] ? row_art($r['art_path'], $r['art_version']) : '' ?>>
                        <td class="rowactions">
                            <?php if ($r['link'] !== null): ?>
                                <input type="text" class="visually-hidden" id="given-link-<?= (int) $r['winner_id'] ?>" value="<?= e($r['link']) ?>" readonly tabindex="-1" aria-hidden="true">
                                <button type="button" class="btn small<?= $fresh === (int) $r['winner_id'] ? ' primary' : '' ?>" data-copy="#given-link-<?= (int) $r['winner_id'] ?>"><?= icon_label('link', __('ui.action.copy_link')) ?></button>
                                <form method="post" action="<?= e(url('/keys/given/cancel')) ?>">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="winner_id" value="<?= (int) $r['winner_id'] ?>">
                                    <button type="submit" class="btn small ghost" data-confirm="<?= e(__($r['is_direct'] ? 'ui.message.gift_cancel_confirm' : 'ui.message.winner_cancel_confirm')) ?>"><?= icon_label('undo', __('ui.action.withdraw')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td data-col="who" data-label="<?= e(__('ui.label.beneficiary')) ?>">
                            <a href="https://twitch.tv/<?= e(rawurlencode((string) $r['twitch_login'])) ?>" target="_blank" rel="noopener noreferrer"><strong><?= e((string) ($r['display_name'] ?: $r['twitch_login'])) ?></strong></a>
                            <small class="muted block">@<?= e((string) $r['twitch_login']) ?></small>
                        </td>
                        <td data-card-title data-col="key" data-label="<?= e(__('ui.field.key')) ?>">
                            <?php if ($r['key_id']): ?>
                                <?= e((string) $r['game_title']) ?>
                                <small class="muted block"><?= e(code_label('game_platform', $r['platform_code']) . ($r['key_site'] ? ' · ' . code_label('key_platform', $r['key_site']) : '')) ?></small>
                            <?php elseif (!$r['is_direct'] && $r['winner_mode'] === 'pick' && in_array($r['state'], ['waiting', 'expired'], true)): ?>
                                <span class="muted"><?= e(__('ui.label.given_picks')) ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-col="from" data-label="<?= e(__('ui.label.given_from')) ?>">
                            <?php if ($r['is_direct']): ?>
                                <span class="badge"><?= e(__('ui.label.direct_gift')) ?></span>
                            <?php else: ?>
                                <a href="<?= e(url('/giveaways/show?id=' . (int) $r['giveaway_id'])) ?>"><?= e((string) $r['giveaway_title']) ?></a>
                            <?php endif; ?>
                        </td>
                        <td data-col="how" data-label="<?= e(__('ui.label.given_how')) ?>"><?= e(code_label('winner_method', $r['method'])) ?></td>
                        <td data-col="sent" data-sort="<?= e(sort_key($r['sent_at'])) ?>" data-label="<?= e(__('ui.label.given_sent')) ?>"><?= e(fmt_datetime($r['sent_at'])) ?></td>
                        <td data-col="taken" data-sort="<?= e(sort_key($r['claimed_at'])) ?>" data-label="<?= e(__('ui.label.given_taken')) ?>"><?= e($r['claimed_at'] ? fmt_datetime($r['claimed_at']) : '—') ?></td>
                        <td data-col="state" data-label="<?= e(__('ui.field.status')) ?>">
                            <span class="badge winner-<?= e($r['state']) ?>"><?= e(__('ui.label.winner_state_' . $r['state'])) ?></span>
                            <?php if ($r['state'] === 'waiting'): ?><small class="muted block"><?= e(sprintf(__('ui.label.given_until'), fmt_date($r['expires_at']))) ?></small><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($twitchReady): ?>
    <?= View::partial('keys/_give_form', ['giftable' => $giftable]) ?>
<?php endif; ?>
