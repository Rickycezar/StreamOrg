<?php
/**
 * "My prizes": the keys a viewer (or a creator, through their Twitch) won,
 * as game cards grouped by streamer, links still waiting to be used, and
 * deleting the viewer profile.
 *
 * @var array $viewer @var array $prizes @var array $pending @var bool $creator
 */
$byStreamer = [];

foreach ($prizes as $p) {
    $byStreamer[$p['streamer_name'] ?: $p['streamer_username']][] = $p;
}

$art = static fn (int $gameId): ?string => GameImages::urls(Database::connection(), $gameId)['portrait'] ?? null;
?>
<section class="pub-prizes-page">
    <header class="pub-hero">
        <span class="pub-avatar">
            <?php if (!empty($viewer['avatar_url'])): ?><img src="<?= e($viewer['avatar_url']) ?>" alt="" referrerpolicy="no-referrer"><?php else: ?><span><?= e(mb_strtoupper(mb_substr((string) $viewer['twitch_login'], 0, 1))) ?></span><?php endif; ?>
        </span>
        <span class="lp-eyebrow"><?= e($viewer['display_name'] ?: $viewer['twitch_login']) ?></span>
        <h1><?= e(__('ui.viewer.prizes_title')) ?></h1>
        <p class="pub-lead"><?= e(__('ui.viewer.prizes_intro')) ?></p>
    </header>

    <?php if ($pending !== []): ?>
        <div class="pub-card pub-pending">
            <h2><?= e(__('ui.viewer.waiting_title')) ?></h2>
            <?php foreach ($pending as $w): ?>
                <div class="pub-pending-row">
                    <span><strong><?= e($w['title']) ?></strong><small><?= e($w['streamer']) ?> · <?= e(sprintf(__('ui.label.until_date'), fmt_datetime($w['expires_at'], 'd/m H:i'))) ?></small></span>
                    <?php if ($w['token']): ?><a class="lp-btn primary small" href="<?= e(url('/claim?t=' . rawurlencode($w['token']))) ?>"><?= e(__('ui.viewer.claim_now')) ?></a><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($byStreamer === []): ?>
        <div class="pub-state tone-muted">
            <span class="pub-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11h16v10H4zM2 7h20v4H2zM12 7v14M12 7c-2-4-6-4-6-1.5S9 7 12 7zM12 7c2-4 6-4 6-1.5S15 7 12 7z"/></svg></span>
            <h2><?= e(__('ui.viewer.no_prizes_title')) ?></h2>
            <p><?= e(__('ui.viewer.no_prizes')) ?></p>
        </div>
    <?php endif; ?>

    <?php foreach ($byStreamer as $streamer => $list): ?>
        <h2 class="pub-group"><?= e(sprintf(__('ui.viewer.from_streamer'), $streamer)) ?></h2>
        <div class="pub-grid">
            <?php foreach ($list as $p): $field = 'prize-' . (int) $p['prize_id']; $src = $art((int) $p['game_id']); $redeem = $p['code'] ? Giveaways::redeemUrl($p['platform_code'], (string) $p['code']) : null; ?>
                <article class="pub-card pub-prize-card">
                    <span class="pub-prize-art"><?php if ($src): ?><img src="<?= e($src) ?>" alt="" loading="lazy"><?php endif; ?></span>
                    <div class="pub-prize-body">
                        <strong><?= e($p['game_title']) ?></strong>
                        <small><?= e(code_label('game_platform', $p['platform_code'])) ?> · <?= e($p['title']) ?></small>
                        <small><?= e(sprintf(__('ui.viewer.claimed_on'), fmt_date($p['claimed_at']))) ?><?= $p['redeem_by'] ? ' · ' . e(sprintf(__('ui.label.redeem_by_date'), fmt_date($p['redeem_by']))) : '' ?></small>
                        <details class="pub-key">
                            <summary><?= e(__('ui.viewer.show_key')) ?></summary>
                            <div class="pub-code small">
                                <input type="text" id="<?= e($field) ?>" value="<?= e((string) $p['code']) ?>" readonly aria-label="<?= e(__('ui.viewer.your_key')) ?>">
                                <button type="button" class="lp-btn ghost small" data-copy="#<?= e($field) ?>" data-copied="<?= e(__('ui.viewer.copied')) ?>"><?= e(__('ui.action.copy')) ?></button>
                            </div>
                            <?php if ($redeem): ?>
                                <a class="pub-redeem" href="<?= e($redeem) ?>" target="_blank" rel="noopener noreferrer"><?= e(sprintf(__('ui.viewer.redeem_on'), (string) Giveaways::redeemStore($p['platform_code']))) ?> ↗</a>
                            <?php endif; ?>
                        </details>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <?php if (!$creator): ?>
        <details class="pub-danger">
            <summary><?= e(__('ui.viewer.delete_title')) ?></summary>
            <p><?= e(__('ui.viewer.delete_explain')) ?></p>
            <form method="post" action="<?= e(url('/viewer/delete')) ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="lp-btn ghost small" data-confirm="<?= e(__('ui.viewer.delete_confirm')) ?>"><?= e(__('ui.viewer.delete_title')) ?></button>
            </form>
        </details>
    <?php endif; ?>
</section>
