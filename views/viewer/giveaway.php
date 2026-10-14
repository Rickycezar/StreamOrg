<?php
/**
 * A giveaway's public prize page (the link the chat bot gives): the
 * streamer, where the giveaway stands, how to enter by chat, its rules,
 * and its prizes — the ones still to win and the ones already won, with
 * the winner's name. Never a key code.
 *
 * @var array $giveaway @var list<array> $prizes @var ?string $join the chat command that enters it, when it takes entries now
 */
$g        = $giveaway;
$streamer = $g['streamer_name'] ?: $g['streamer_username'];
$avatar   = Avatars::url(['avatar_path' => $g['avatar_path'], 'avatar_updated_at' => $g['avatar_updated_at']]);
$art      = static fn (int $gameId): ?array => GameImages::boxArt(Database::connection(), $gameId);
$open     = array_values(array_filter($prizes, static fn (array $p): bool => $p['winner_name'] === null));
$won      = array_values(array_filter($prizes, static fn (array $p): bool => $p['winner_name'] !== null));

$status = match ($g['status']) {
    'open'    => $g['ends_at'] ? sprintf(__('ui.viewer.giveaway_open_until'), fmt_datetime($g['ends_at'])) : __('ui.viewer.giveaway_open'),
    'planned' => $g['starts_at'] ? sprintf(__('ui.viewer.giveaway_starts'), fmt_datetime($g['starts_at'])) : __('ui.viewer.giveaway_soon'),
    'closed'  => __('ui.viewer.giveaway_closed'),
    default   => __('ui.viewer.giveaway_finished'),
};

$card = static function (array $p) use ($art): void {
    $src = $art((int) $p['game_id']); ?>
    <article class="pub-card pub-prize-card<?= $p['winner_name'] !== null ? ' is-won' : '' ?>">
        <?= View::partial('viewer/_art', ['art' => $src, 'class' => 'pub-prize-art']) ?>
        <div class="pub-prize-body">
            <strong><?= e($p['game_title']) ?></strong>
            <small><?= e(code_label('game_platform', $p['platform_code'])) ?></small>
            <?php if ($p['winner_name'] !== null): ?>
                <span class="pub-winner"><?= e(sprintf(__('ui.viewer.won_by'), $p['winner_name'])) ?></span>
                <?php if ($p['claimed_at']): ?><small><?= e(sprintf(__('ui.viewer.claimed_on'), fmt_date($p['claimed_at']))) ?></small><?php endif; ?>
            <?php endif; ?>
        </div>
    </article>
<?php };
?>
<section class="pub-prizes-page pub-giveaway">
    <header class="pub-hero">
        <span class="pub-avatar">
            <?php if ($avatar): ?><img src="<?= e($avatar) ?>" alt=""><?php else: ?><span><?= e(mb_strtoupper(mb_substr($streamer, 0, 1))) ?></span><?php endif; ?>
        </span>
        <span class="lp-eyebrow"><?= e(sprintf(__('ui.viewer.giveaway_by'), $streamer)) ?></span>
        <h1><?= e($g['title']) ?></h1>
        <p class="pub-lead"><?= e($status) ?></p>
    </header>

    <?php if ($join !== null): ?>
        <div class="pub-card pub-join">
            <span class="lp-eyebrow"><?= e(__('ui.viewer.how_to_enter')) ?></span>
            <p><?= e(__('ui.viewer.type_in_chat')) ?></p>
            <code class="pub-join-command"><?= e($join) ?></code>
            <?php if (!empty($g['twitch_login'])): ?>
                <a class="lp-btn pub-twitch small" href="https://www.twitch.tv/<?= e(rawurlencode((string) $g['twitch_login'])) ?>" target="_blank" rel="noopener noreferrer"><?= e(sprintf(__('ui.viewer.open_chat'), $streamer)) ?> ↗</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($g['rules_note'])): ?>
        <details class="pub-rules">
            <summary><?= e(__('ui.viewer.rules')) ?></summary>
            <p><?= nl2br(e((string) $g['rules_note'])) ?></p>
        </details>
    <?php endif; ?>

    <?php if ($prizes === []): ?>
        <div class="pub-state tone-muted">
            <span class="pub-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11h16v10H4zM2 7h20v4H2zM12 7v14M12 7c-2-4-6-4-6-1.5S9 7 12 7zM12 7c2-4 6-4 6-1.5S15 7 12 7z"/></svg></span>
            <h2><?= e(__('ui.viewer.no_prizes_yet_title')) ?></h2>
            <p><?= e(__('ui.viewer.no_prizes_yet')) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($open !== []): ?>
        <h2 class="pub-group"><?= e(sprintf(__('ui.viewer.prizes_to_win'), count($open))) ?></h2>
        <div class="pub-grid">
            <?php foreach ($open as $p) { $card($p); } ?>
        </div>
    <?php endif; ?>

    <?php if ($won !== []): ?>
        <h2 class="pub-group"><?= e(sprintf(__('ui.viewer.prizes_won'), count($won))) ?></h2>
        <div class="pub-grid">
            <?php foreach ($won as $p) { $card($p); } ?>
        </div>
    <?php endif; ?>
</section>
