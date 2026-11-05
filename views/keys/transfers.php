<?php
/**
 * Keys → Transfers: keys passed between creators' vaults without anyone
 * seeing the code (see KeyTransfers). On top, the transfers waiting for
 * this creator's answer, each key with who has seen its code so far;
 * then the form to send keys to a creator, and every transfer sent and
 * received. People appear by handle only, never by name or e-mail.
 *
 * @var list<array> $transfers @var list<array> $sendable @var list<int> $picked
 * @var bool $vaultLocked @var string $myHandle
 */
$waiting = array_values(array_filter($transfers, static fn (array $t): bool => $t['incoming'] && $t['status'] === 'pending'));
$others  = array_values(array_filter($transfers, static fn (array $t): bool => !($t['incoming'] && $t['status'] === 'pending')));

$seenList = static function (array $seen): string {
    if ($seen === []) {
        return '<span class="badge ok">' . e(__('ui.transfer.never_seen')) . '</span>';
    }

    return implode(' ', array_map(static fn (array $r): string => '<span class="badge warn" title="' . e(fmt_datetime($r['happened_at'])) . '">'
        . e(sprintf(__('ui.transfer.seen_short_' . $r['kind']), '@' . $r['handle'])) . '</span>', $seen));
};

$keyLine = static fn (array $item): string => implode(' · ', array_filter([
    code_label('game_platform', $item['platform_code']),
    code_label('key_platform', $item['source_code']),
    code_label('key_type', $item['key_type']),
    $item['content_type'] === 'dlc' ? code_label('content_type', 'dlc') : null,
]));
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.key_transfers')) ?></h1>
    <?php $helpPage = 'keys'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
</div>
<p class="muted"><?= e(sprintf(__('ui.transfer.intro'), '@' . $myHandle)) ?></p>

<?php if ($vaultLocked): ?>
    <?php $back = '/keys/transfers'; require dirname(__DIR__) . '/partials/vault_unlock.php'; ?>
<?php endif; ?>

<?php foreach ($waiting as $t): ?>
    <section class="card transfer-card incoming">
        <div class="transfer-head">
            <div>
                <span class="transfer-kicker"><?= e(__('ui.transfer.waiting_title')) ?></span>
                <h2><?= e(sprintf(__('ui.transfer.from'), '@' . $t['from_handle'])) ?></h2>
                <small class="muted"><?= e(sprintf(__('ui.transfer.sent_expires'), fmt_datetime($t['created_at']), fmt_datetime($t['expires_at']))) ?></small>
            </div>
            <div class="card-actions">
                <form method="post" action="<?= e(url('/keys/transfers/accept')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="btn primary"><?= e(sprintf(__('ui.transfer.accept'), count($t['items']))) ?></button>
                </form>
                <form method="post" action="<?= e(url('/keys/transfers/decline')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="btn" data-confirm="<?= e(__('ui.transfer.decline_confirm')) ?>"><?= e(__('ui.transfer.decline')) ?></button>
                </form>
            </div>
        </div>
        <?php if ($t['message'] !== null): ?>
            <blockquote class="transfer-message"><?= e((string) $t['message']) ?></blockquote>
        <?php endif; ?>
        <ul class="transfer-items">
            <?php foreach ($t['items'] as $item): ?>
                <li>
                    <span><strong><?= game_name($item['title']) ?></strong> <small class="muted"><?= e($keyLine($item)) ?></small></span>
                    <span class="transfer-seen"><small class="muted"><?= e(__('ui.transfer.seen_by')) ?></small> <?= $seenList($item['seen']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="muted small"><?= e(__('ui.transfer.accept_note')) ?></p>
    </section>
<?php endforeach; ?>

<section class="card" id="send">
    <h2><?= e(__('ui.transfer.send_title')) ?></h2>
    <p class="muted small"><?= e(__('ui.transfer.send_intro')) ?></p>
    <?php if ($sendable === []): ?>
        <p class="empty"><?= e(__('ui.transfer.nothing_to_send')) ?></p>
    <?php else: ?>
        <form method="post" action="<?= e(url('/keys/transfers')) ?>" class="transfer-send" autocomplete="off" data-transfer-form>
            <?= Csrf::field() ?>
            <div class="transfer-send-side">
                <div class="twitch-pick" data-twitch-pick data-search-url="<?= e(url('/keys/transfers/search')) ?>" data-empty="<?= e(__('ui.transfer.search_none')) ?>">
                    <label>
                        <span><?= e(__('ui.transfer.to')) ?></span>
                        <input type="search" name="handle" required maxlength="40" spellcheck="false" autocomplete="off"
                               placeholder="<?= e(__('ui.transfer.to_placeholder')) ?>" data-twitch-pick-search data-transfer-handle>
                        <small class="muted"><?= e(__('ui.transfer.to_hint')) ?></small>
                    </label>
                    <input type="hidden" value="" data-twitch-pick-id>
                    <div class="twitch-pick-chosen" data-twitch-pick-chosen hidden>
                        <img alt="" data-twitch-pick-avatar>
                        <span><strong data-twitch-pick-name></strong> <small class="muted" data-twitch-pick-login></small></span>
                        <button type="button" class="btn small" data-twitch-pick-change><?= e(__('ui.action.change')) ?></button>
                    </div>
                    <div class="cast-add-results twitch-pick-results" data-twitch-pick-results></div>
                </div>
                <label>
                    <span><?= e(__('ui.transfer.message')) ?> <small class="muted">(<?= e(__('ui.transfer.optional')) ?>)</small></span>
                    <textarea name="message" rows="3" maxlength="<?= KeyTransfers::MESSAGE_MAX ?>"></textarea>
                </label>
                <p class="muted small"><?= e(sprintf(__('ui.transfer.send_note'), KeyTransfers::EXPIRES_DAYS)) ?></p>
                <div class="card-actions">
                    <button type="submit" class="btn primary" data-confirm-template="<?= e(__('ui.transfer.send_confirm')) ?>"><?= e(__('ui.transfer.send')) ?></button>
                </div>
            </div>
            <div class="transfer-pick">
                <div class="transfer-pick-head">
                    <span><?= e(__('ui.transfer.keys')) ?> <small class="muted" data-transfer-count data-template="<?= e(__('ui.transfer.selected')) ?>"></small></span>
                    <input type="search" placeholder="<?= e(__('ui.label.search_games')) ?>" data-transfer-filter>
                </div>
                <ul class="transfer-pick-list">
                    <?php foreach ($sendable as $key): ?>
                        <li data-title="<?= e(mb_strtolower((string) $key['title'])) ?>">
                            <label>
                                <input type="checkbox" name="key_ids[]" value="<?= (int) $key['id'] ?>" <?= in_array((int) $key['id'], $picked, true) ? 'checked' : '' ?>>
                                <span><strong><?= game_name($key['title']) ?></strong>
                                    <small class="muted"><?= e($keyLine($key)) ?><?= $key['status'] === 'for_giveaway' ? ' · ' . e(code_label('key_status', 'for_giveaway')) : '' ?></small>
                                    <?php if (!$key['code_seen']): ?><span class="badge ok"><?= e(__('ui.transfer.sealed')) ?></span><?php endif; ?>
                                </span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= e(__('ui.transfer.history_title')) ?></h2>
    <?php if ($others === []): ?>
        <p class="empty"><?= e(__('ui.transfer.history_none')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table data-sortable data-table="key_transfers" class="transfer-table">
                <thead>
                <tr>
                    <th class="col-actions"><span class="visually-hidden"><?= e(__('ui.label.actions')) ?></span></th>
                    <th data-col="who"><?= e(__('ui.transfer.col_who')) ?></th>
                    <th data-col="keys" data-nosort><?= e(__('ui.transfer.keys')) ?></th>
                    <th data-col="sent"><?= e(__('ui.transfer.col_sent')) ?></th>
                    <th data-col="state"><?= e(__('ui.field.status')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($others as $t): ?>
                    <tr>
                        <td class="rowactions">
                            <?php if (!$t['incoming'] && $t['status'] === 'pending'): ?>
                                <form method="post" action="<?= e(url('/keys/transfers/cancel')) ?>">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <button type="submit" class="btn small" data-confirm="<?= e(__('ui.transfer.cancel_confirm')) ?>"><?= icon_label('unlink', __('ui.transfer.cancel')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td data-card-title data-col="who" data-label="<?= e(__('ui.transfer.col_who')) ?>">
                            <?= e($t['incoming'] ? sprintf(__('ui.transfer.from'), '@' . $t['from_handle']) : sprintf(__('ui.transfer.to_handle'), '@' . $t['to_handle'])) ?>
                            <?php if ($t['message'] !== null): ?><small class="muted block">“<?= e((string) $t['message']) ?>”</small><?php endif; ?>
                        </td>
                        <td data-col="keys" data-label="<?= e(__('ui.transfer.keys')) ?>">
                            <ul class="transfer-items compact">
                                <?php foreach ($t['items'] as $item): ?>
                                    <li>
                                        <span><?= game_name($item['title'], true) ?> <small class="muted"><?= e(code_label('game_platform', $item['platform_code'])) ?></small>
                                            <?php if ($item['outcome'] === 'duplicate'): ?><span class="badge warn"><?= e(__('ui.transfer.outcome_duplicate')) ?></span><?php endif; ?>
                                        </span>
                                        <span class="transfer-seen"><?= $seenList($item['seen']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </td>
                        <td class="dates" data-col="sent" data-label="<?= e(__('ui.transfer.col_sent')) ?>" data-sort="<?= e(sort_key($t['created_at'])) ?>">
                            <?= e(fmt_datetime($t['created_at'])) ?>
                            <?php if ($t['decided_at'] !== null): ?><small class="muted block"><?= e(sprintf(__('ui.transfer.decided_on'), fmt_datetime($t['decided_at']))) ?></small><?php endif; ?>
                        </td>
                        <td data-col="state" data-label="<?= e(__('ui.field.status')) ?>">
                            <span class="badge transfer-<?= e($t['status']) ?>"><?= e(__('ui.transfer.status_' . $t['status'])) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
