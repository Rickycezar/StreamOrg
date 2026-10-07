<?php
/**
 * The page behind a winner's claim link, for the general public.
 *
 * One of these, in order: an unknown link; a prize taken back by the
 * StreamOrg team; the winner's own claimed key
 * (with a little celebration right after claiming); someone else's claimed
 * prize; a withdrawn or expired link; "sign in with Twitch"; the wrong
 * account; the key set aside for them (ready to reveal, or still locked by
 * the streamer); nothing left; or the keys to choose from.
 *
 * @var string $token @var ?array $claim @var ?array $viewer @var bool $celebrate
 */
$back = '/claim?t=' . rawurlencode($token);

$state = static function (string $icon, string $title, string $text, string $tone = 'info'): void {
    $paths = [
        'link'  => 'M10 14a4 4 0 0 0 5.6 0l3-3a4 4 0 0 0-5.6-5.6l-1 1M14 10a4 4 0 0 0-5.6 0l-3 3a4 4 0 0 0 5.6 5.6l1-1',
        'clock' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
        'lock'  => 'M7 11V8a5 5 0 0 1 10 0v3M5 11h14v10H5zM12 15v2',
        'user'  => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c0-4 4-6 8-6s8 2 8 6',
        'gift'  => 'M4 11h16v10H4zM2 7h20v4H2zM12 7v14M12 7c-2-4-6-4-6-1.5S9 7 12 7zM12 7c2-4 6-4 6-1.5S15 7 12 7z',
        'check' => 'M9 12l2 2 4-4M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
    ];
    echo '<div class="pub-state tone-' . e($tone) . '"><span class="pub-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . e($paths[$icon]) . '"/></svg></span>'
        . '<h2>' . e($title) . '</h2><p>' . e($text) . '</p></div>';
};
?>
<section class="pub-claim">
    <?php if ($claim === null): ?>
        <?php $state('link', __('ui.viewer.link_invalid_title'), __('ui.viewer.link_invalid'), 'muted'); ?>
    <?php else:
        $w = $claim['winner'];
        $g = $claim['giveaway'];
        $streamer = $g['streamer_name'] ?: $g['streamer_username'];
        $avatar = Avatars::url(['avatar_path' => $g['avatar_path'], 'avatar_updated_at' => $g['avatar_updated_at']]);
        $mine = $viewer !== null && (string) $viewer['twitch_user_id'] === (string) $w['twitch_user_id'];
        $art = static fn (int $gameId): ?string => GameImages::urls(Database::connection(), $gameId)['portrait'] ?? null;
    ?>
        <header class="pub-hero">
            <span class="pub-avatar">
                <?php if ($avatar): ?><img src="<?= e($avatar) ?>" alt=""><?php else: ?><span><?= e(mb_strtoupper(mb_substr($streamer, 0, 1))) ?></span><?php endif; ?>
            </span>
            <span class="lp-eyebrow"><?= e(sprintf(__('ui.viewer.giveaway_by'), $streamer)) ?></span>
            <h1><?= e($g['title']) ?></h1>
        </header>

        <?php if ($w['removed_at'] !== null): ?>
            <?php $state('link', __('ui.viewer.removed_title'), __('ui.viewer.removed'), 'muted'); ?>
        <?php elseif (!empty($claim['claimed'])): $prize = $claim['claimed']; $redeem = $prize['code'] ? Giveaways::redeemUrl($prize['platform_code'], $prize['code']) : null; ?>
            <div class="pub-card pub-reveal<?= $celebrate ? ' celebrate' : '' ?>">
                <?php if ($celebrate): ?>
                    <div class="confetti" aria-hidden="true"><?php for ($i = 0; $i < 24; $i++): ?><i style="--i: <?= $i ?>"></i><?php endfor; ?></div>
                <?php endif; ?>
                <div class="pub-reveal-game">
                    <?php if ($src = $art((int) $prize['game_id'])): ?><img src="<?= e($src) ?>" alt=""><?php endif; ?>
                    <div>
                        <span class="lp-eyebrow"><?= e(__($celebrate ? 'ui.viewer.you_won' : 'ui.viewer.your_key')) ?></span>
                        <h2><?= e($prize['title']) ?></h2>
                        <small><?= e(code_label('game_platform', $prize['platform_code'])) ?><?= $prize['expires_at'] ? ' · ' . e(sprintf(__('ui.label.redeem_by_date'), fmt_date($prize['expires_at']))) : '' ?></small>
                    </div>
                </div>
                <?php if ($prize['code']): ?>
                    <div class="pub-code">
                        <input type="text" id="claim-code" value="<?= e($prize['code']) ?>" readonly aria-label="<?= e(__('ui.viewer.your_key')) ?>">
                        <button type="button" class="lp-btn ghost small" data-copy="#claim-code" data-copied="<?= e(__('ui.viewer.copied')) ?>"><?= e(__('ui.action.copy')) ?></button>
                    </div>
                    <div class="pub-actions">
                        <?php if ($redeem): ?>
                            <a class="lp-btn primary" href="<?= e($redeem) ?>" target="_blank" rel="noopener noreferrer"><?= e(sprintf(__('ui.viewer.redeem_on'), (string) Giveaways::redeemStore($prize['platform_code']))) ?></a>
                        <?php endif; ?>
                        <a class="lp-btn ghost" href="<?= e(url('/prizes')) ?>"><?= e(__('ui.viewer.prizes_title')) ?></a>
                    </div>
                    <p class="pub-note"><?= e(__('ui.viewer.key_saved_note')) ?></p>
                <?php else: ?>
                    <p class="pub-note"><?= e(__('ui.message.claim_unreadable')) ?></p>
                <?php endif; ?>
            </div>
        <?php elseif ($w['claimed_at'] !== null): ?>
            <?php $state('check', __('ui.viewer.already_claimed_title'), __('ui.viewer.already_claimed'), 'muted'); ?>
        <?php elseif ($w['cancelled_at'] !== null): ?>
            <?php $state('link', __('ui.viewer.withdrawn_title'), __('ui.message.claim_cancelled'), 'muted'); ?>
        <?php elseif (strtotime((string) $w['expires_at']) < time()): ?>
            <?php $state('clock', __('ui.viewer.expired_title'), __('ui.message.claim_expired'), 'muted'); ?>
        <?php elseif ($viewer === null): ?>
            <div class="pub-card pub-signin">
                <span class="pub-state-icon tone-gift"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11h16v10H4zM2 7h20v4H2zM12 7v14M12 7c-2-4-6-4-6-1.5S9 7 12 7zM12 7c2-4 6-4 6-1.5S15 7 12 7z"/></svg></span>
                <h2><?= e(sprintf(__('ui.viewer.congrats'), $w['display_name'] ?: $w['twitch_login'])) ?></h2>
                <p><?= e(sprintf(__('ui.viewer.sign_in_to_claim'), fmt_datetime($w['expires_at']))) ?></p>
                <a class="lp-btn pub-twitch" href="<?= e(url('/viewer/login?back=' . rawurlencode($back))) ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3h16v11l-4 4h-4l-3 3v-3H4zM10 8v4M15 8v4"/></svg>
                    <?= e(__('ui.viewer.sign_in_twitch')) ?>
                </a>
                <p class="pub-note"><?= e(__('ui.viewer.twitch_identity_only')) ?></p>
            </div>
        <?php elseif (!$mine): ?>
            <?php $state('user', __('ui.viewer.not_yours_title'), sprintf(__('ui.viewer.not_yours'), $w['twitch_login'], $viewer['twitch_login']), 'warn'); ?>
        <?php elseif ($claim['reserved'] !== null): $r = $claim['reserved']; $src = $art((int) $r['game_id']); ?>
            <div class="pub-card pub-reserved<?= $r['ready'] ? ' ready' : '' ?>">
                <div class="pub-reveal-game">
                    <?php if ($src): ?><img src="<?= e($src) ?>" alt=""><?php endif; ?>
                    <div>
                        <span class="lp-eyebrow"><?= e(__($r['ready'] ? 'ui.viewer.reserved_ready_eyebrow' : 'ui.viewer.reserved_eyebrow')) ?></span>
                        <h2><?= e($r['title']) ?></h2>
                        <small><?= e(code_label('game_platform', $r['platform_code'])) ?></small>
                    </div>
                </div>
                <?php if ($r['ready']): ?>
                    <p><?= e(__('ui.viewer.reserved_ready')) ?></p>
                    <form method="post" action="<?= e(url('/claim')) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="t" value="<?= e($token) ?>">
                        <button type="submit" class="lp-btn primary pub-cta"><?= e(__('ui.viewer.reveal_key')) ?></button>
                    </form>
                <?php else: ?>
                    <p><?= e(sprintf(__('ui.viewer.reserved_' . $claim['why']), $r['title'], $streamer)) ?></p>
                    <p class="pub-note"><?= e(sprintf(__('ui.viewer.reserved_come_back'), fmt_datetime($w['expires_at']))) ?></p>
                <?php endif; ?>
            </div>
        <?php elseif ($claim['prizes'] === []): ?>
            <?php $state('gift', __('ui.viewer.gone_title'), __('ui.message.claim_prize_gone'), 'muted'); ?>
        <?php else: $pick = $g['winner_mode'] === 'pick'; ?>
            <form method="post" action="<?= e(url('/claim')) ?>" class="pub-card pub-pick">
                <?= Csrf::field() ?>
                <input type="hidden" name="t" value="<?= e($token) ?>">
                <h2><?= e(sprintf(__('ui.viewer.congrats'), $w['display_name'] ?: $w['twitch_login'])) ?></h2>
                <p><?= e(__($pick ? 'ui.viewer.pick_one' : 'ui.viewer.your_prize')) ?></p>
                <?php if ($pick && $claim['locked']): ?>
                    <p class="pub-locked-note"><?= e(sprintf(__('ui.viewer.pick_while_locked'), $streamer)) ?></p>
                <?php endif; ?>
                <div class="pub-prizes<?= $pick ? '' : ' single' ?>">
                    <?php foreach ($claim['prizes'] as $i => $p): $src = $art((int) $p['game_id']); ?>
                        <label class="pub-prize">
                            <?php if ($pick): ?>
                                <input type="radio" name="prize_id" value="<?= (int) $p['id'] ?>" <?= $i === 0 ? 'checked' : '' ?> required>
                            <?php endif; ?>
                            <span class="pub-prize-art"><?php if ($src): ?><img src="<?= e($src) ?>" alt="" loading="lazy"><?php endif; ?></span>
                            <span class="pub-prize-text">
                                <strong><?= e($p['title']) ?></strong>
                                <small><?= e(code_label('game_platform', $p['platform_code'])) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="lp-btn primary pub-cta"><?= e(__($pick ? 'ui.viewer.claim_chosen' : 'ui.viewer.reveal_key')) ?></button>
            </form>
        <?php endif; ?>

        <?php if ($g['rules_note']): ?>
            <details class="pub-rules">
                <summary><?= e(__('ui.field.rules')) ?></summary>
                <p><?= nl2br(e($g['rules_note'])) ?></p>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</section>
