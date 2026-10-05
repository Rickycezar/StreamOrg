<?php
/**
 * The page behind a winner's claim link.
 *
 * @var string  $token
 * @var ?array  $claim     winner, giveaway and the prizes they may take; null for an unknown link
 * @var ?array  $viewer    the signed-in viewer (or creator through their Twitch)
 * @var ?string $revealed  the code just claimed, shown once
 */
$back = '/claim?t=' . rawurlencode($token);
?>
<div class="viewer-page">
    <?php require dirname(__DIR__) . '/partials/viewer_bar.php'; ?>

    <section class="card claim-card">
        <?php if ($claim === null): ?>
            <h1><?= e(__('ui.viewer.link_invalid_title')) ?></h1>
            <p class="muted"><?= e(__('ui.viewer.link_invalid')) ?></p>
        <?php else:
            $w = $claim['winner'];
            $g = $claim['giveaway'];
            $streamer = $g['streamer_name'] ?: $g['streamer_username'];
            $avatar = Avatars::url(['avatar_path' => $g['avatar_path'], 'avatar_updated_at' => $g['avatar_updated_at']]);
            $mine = $viewer !== null && (string) $viewer['twitch_user_id'] === (string) $w['twitch_user_id'];
        ?>
            <div class="claim-head">
                <?php if ($avatar): ?><img class="claim-streamer" src="<?= e($avatar) ?>" alt=""><?php endif; ?>
                <div>
                    <p class="muted small"><?= e(sprintf(__('ui.viewer.giveaway_by'), $streamer)) ?></p>
                    <h1><?= e($g['title']) ?></h1>
                </div>
            </div>

            <?php if ($revealed !== null): ?>
                <div class="claim-reveal">
                    <p><strong><?= e(__('ui.viewer.your_key')) ?></strong></p>
                    <div class="claim-code">
                        <input type="text" id="claim-code" class="copy-field code" value="<?= e($revealed) ?>" readonly>
                        <button type="button" class="btn primary" data-copy="#claim-code"><?= e(__('ui.action.copy')) ?></button>
                    </div>
                    <p class="muted small"><?= e(__('ui.viewer.key_saved')) ?> <a href="<?= e(url('/prizes')) ?>"><?= e(__('ui.viewer.prizes_title')) ?></a></p>
                </div>
            <?php elseif ($w['claimed_at'] !== null): ?>
                <p><?= e(__('ui.viewer.already_claimed')) ?></p>
                <?php if ($mine): ?><a class="btn primary" href="<?= e(url('/prizes')) ?>"><?= e(__('ui.viewer.prizes_title')) ?></a><?php endif; ?>
            <?php elseif ($w['cancelled_at'] !== null): ?>
                <p><?= e(__('ui.message.claim_cancelled')) ?></p>
            <?php elseif (strtotime((string) $w['expires_at']) < time()): ?>
                <p><?= e(__('ui.message.claim_expired')) ?></p>
            <?php elseif ($viewer === null): ?>
                <p><?= e(sprintf(__('ui.viewer.won_by'), $w['display_name'] ?: $w['twitch_login'])) ?></p>
                <p class="muted"><?= e(sprintf(__('ui.viewer.sign_in_to_claim'), fmt_datetime($w['expires_at']))) ?></p>
                <a class="btn twitch-signin" href="<?= e(url('/viewer/login?back=' . rawurlencode($back))) ?>" data-turbo="false">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3h16v11l-4 4h-4l-3 3v-3H4zM10 8v4M15 8v4"/></svg>
                    <?= e(__('ui.viewer.sign_in_twitch')) ?>
                </a>
                <p class="muted small"><?= e(__('ui.viewer.twitch_identity_only')) ?></p>
            <?php elseif (!$mine): ?>
                <div class="flash flash-error"><?= e(sprintf(__('ui.viewer.not_yours'), $w['twitch_login'], $viewer['twitch_login'])) ?></div>
            <?php elseif ($claim['prizes'] === []): ?>
                <p><?= e(__('ui.message.claim_prize_gone')) ?></p>
            <?php else: ?>
                <p><?= e(__($g['winner_mode'] === 'pick' ? 'ui.viewer.pick_one' : 'ui.viewer.your_prize')) ?></p>
                <form method="post" action="<?= e(url('/claim')) ?>" class="claim-form" data-turbo="false">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="t" value="<?= e($token) ?>">
                    <div class="claim-prizes">
                        <?php foreach ($claim['prizes'] as $i => $p): $art = GameImages::urls(Database::connection(), (int) $p['game_id'])['portrait'] ?? null; ?>
                            <label class="claim-prize">
                                <?php if ($g['winner_mode'] === 'pick'): ?>
                                    <input type="radio" name="prize_id" value="<?= (int) $p['id'] ?>" <?= $i === 0 ? 'checked' : '' ?> required>
                                <?php endif; ?>
                                <?php if ($art): ?><img src="<?= e($art) ?>" alt=""><?php else: ?><span class="claim-art-empty"></span><?php endif; ?>
                                <span>
                                    <strong><?= e($p['title']) ?></strong>
                                    <small class="muted"><?= e(code_label('game_platform', $p['platform_code'])) ?><?= $p['expires_at'] ? ' · ' . e(sprintf(__('ui.label.redeem_by_date'), fmt_date($p['expires_at']))) : '' ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn primary big-btn"><?= e(__($g['winner_mode'] === 'pick' ? 'ui.viewer.claim_chosen' : 'ui.viewer.reveal_key')) ?></button>
                </form>
            <?php endif; ?>

            <?php if ($g['rules_note']): ?>
                <details class="claim-rules">
                    <summary><?= e(__('ui.field.rules')) ?></summary>
                    <p><?= nl2br(e($g['rules_note'])) ?></p>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <p class="viewer-foot muted small"><a href="<?= e(url('/privacy')) ?>"><?= e(__('ui.privacy.title')) ?></a></p>
</div>
