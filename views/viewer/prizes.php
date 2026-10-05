<?php
/**
 * "My prizes": the keys a viewer won and claimed, grouped by streamer, and
 * links still waiting to be used.
 *
 * @var array $viewer @var array $prizes @var array $pending @var bool $creator
 */
$byStreamer = [];

foreach ($prizes as $p) {
    $byStreamer[$p['streamer_name'] ?: $p['streamer_username']][] = $p;
}
?>
<div class="viewer-page">
    <?php require dirname(__DIR__) . '/partials/viewer_bar.php'; ?>

    <h1><?= e(__('ui.viewer.prizes_title')) ?></h1>
    <p class="muted"><?= e(__('ui.viewer.prizes_intro')) ?></p>

    <?php if ($pending !== []): ?>
        <section class="card">
            <h2><?= e(__('ui.viewer.waiting_title')) ?></h2>
            <ul class="pending-list">
                <?php foreach ($pending as $w): ?>
                    <li>
                        <span><strong><?= e($w['title']) ?></strong> <small class="muted"><?= e($w['streamer']) ?> · <?= e(sprintf(__('ui.label.until_date'), fmt_datetime($w['expires_at'], 'd/m H:i'))) ?></small></span>
                        <?php if ($w['token']): ?><a class="btn small primary" href="<?= e(url('/claim?t=' . rawurlencode($w['token']))) ?>"><?= e(__('ui.viewer.claim_now')) ?></a><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($byStreamer === []): ?>
        <section class="card"><p class="empty"><?= e(__('ui.viewer.no_prizes')) ?></p></section>
    <?php endif; ?>

    <?php foreach ($byStreamer as $streamer => $list): ?>
        <section class="card">
            <h2><?= e(sprintf(__('ui.viewer.from_streamer'), $streamer)) ?></h2>
            <ul class="prize-list">
                <?php foreach ($list as $i => $p): $field = 'prize-' . (int) $p['prize_id']; ?>
                    <li>
                        <div>
                            <strong><?= e($p['game_title']) ?></strong>
                            <small class="muted"><?= e(code_label('game_platform', $p['platform_code'])) ?> · <?= e($p['title']) ?> · <?= e(fmt_date($p['claimed_at'])) ?><?= $p['redeem_by'] ? ' · ' . e(sprintf(__('ui.label.redeem_by_date'), fmt_date($p['redeem_by']))) : '' ?></small>
                        </div>
                        <details class="prize-code">
                            <summary><?= e(__('ui.viewer.show_key')) ?></summary>
                            <span class="claim-code">
                                <input type="text" id="<?= e($field) ?>" class="copy-field code" value="<?= e((string) $p['code']) ?>" readonly>
                                <button type="button" class="btn small" data-copy="#<?= e($field) ?>"><?= e(__('ui.action.copy')) ?></button>
                            </span>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

    <?php if (!$creator): ?>
        <section class="card danger-card">
            <h2><?= e(__('ui.viewer.delete_title')) ?></h2>
            <p class="muted small"><?= e(__('ui.viewer.delete_explain')) ?></p>
            <form method="post" action="<?= e(url('/viewer/delete')) ?>" data-turbo="false">
                <?= Csrf::field() ?>
                <button type="submit" class="btn small danger-btn" data-confirm="<?= e(__('ui.viewer.delete_confirm')) ?>"><?= e(__('ui.viewer.delete_title')) ?></button>
            </form>
        </section>
    <?php endif; ?>

    <p class="viewer-foot muted small"><a href="<?= e(url('/privacy')) ?>"><?= e(__('ui.privacy.title')) ?></a></p>
</div>
