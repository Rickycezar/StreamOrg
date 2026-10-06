<?php
/**
 * A collab planned together, as one participant sees it: the invitation
 * (when still open), the shared time and any proposal waiting for
 * answers, who is in it, and the participant's own content.
 *
 * @var array $session see CollabSessions::show() @var int $userId
 */
$me        = $session['member_state'];
$isHost    = $session['member_role'] === 'host';
$taking    = array_values(array_filter($session['members'], static fn (array $m): bool => $m['state'] === 'accepted'));
$proposal  = $session['proposed_at'] !== null;
$proposer  = null;
$length    = static fn (?int $minutes): string => $minutes ? sprintf(__('ui.label.together_length'), intdiv($minutes, 60), $minutes % 60) : '';

foreach ($session['members'] as $m) {
    if ((int) $m['user_id'] === (int) $session['proposed_by']) {
        $proposer = $m['name'];
    }
}
?>
<div class="page-head">
    <h1><?= e($session['title']) ?></h1>
    <span class="page-head-actions">
        <?php $helpPage = 'together'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
        <a class="btn small" href="<?= e(url('/collabs')) ?>">← <?= e(__('ui.nav.collabs')) ?></a>
    </span>
</div>
<p class="muted"><?= e(__('ui.message.together_intro')) ?></p>

<?php if ($session['status'] === 'cancelled'): ?>
    <p class="notice"><?= e(__('ui.message.together_was_cancelled')) ?></p>
<?php endif; ?>

<?php if ($me === 'invited' && $session['status'] === 'active'): ?>
    <section class="card together-invite">
        <div>
            <h2><?= e(sprintf(__('ui.message.together_invite_title'), $session['members'][0]['name'] ?? '')) ?></h2>
            <p><?= e(__('ui.message.together_invite_text')) ?></p>
            <?php if ($session['agreed_start']): ?>
                <p class="together-when-small"><?= e(fmt_datetime($session['agreed_start'])) ?> · <?= e($length((int) $session['agreed_minutes'])) ?></p>
            <?php endif; ?>
        </div>
        <div class="card-actions">
            <form method="post" action="<?= e(url('/collabs/session/respond')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
                <input type="hidden" name="accept" value="1">
                <button type="submit" class="btn primary"><?= e(__('ui.action.together_accept')) ?></button>
            </form>
            <form method="post" action="<?= e(url('/collabs/session/respond')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
                <input type="hidden" name="accept" value="0">
                <button type="submit" class="btn"><?= e(__('ui.action.together_decline')) ?></button>
            </form>
        </div>
    </section>
<?php endif; ?>

<div class="together-grid">
    <section class="card together-time">
        <div class="card-head">
            <h2><?= e(__('ui.label.together_when')) ?></h2>
        </div>
        <?php if ($session['agreed_start']): ?>
            <p class="together-when"><?= e(fmt_datetime($session['agreed_start'])) ?></p>
            <p class="muted"><?= e($length((int) $session['agreed_minutes'])) ?> · <?= e(__('ui.message.together_time_shared')) ?></p>
        <?php else: ?>
            <p class="together-when muted"><?= e(__('ui.label.together_no_time')) ?></p>
        <?php endif; ?>

        <?php if ($proposal): ?>
            <div class="together-proposal">
                <strong><?= e(sprintf(__('ui.label.together_proposal_by'), (string) $proposer)) ?></strong>
                <p class="together-when-small">
                    <?= e($session['proposed_start'] ? fmt_datetime($session['proposed_start']) : __('ui.label.together_no_time')) ?>
                    · <?= e($length((int) $session['proposed_minutes'])) ?>
                </p>
                <ul class="together-votes">
                    <?php foreach ($taking as $m): ?>
                        <li class="<?= $m['approves'] ? 'yes' : 'waiting' ?>"><?= e($m['name']) ?> — <?= e(__($m['approves'] ? 'ui.label.together_agreed' : 'ui.label.together_waiting')) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($me === 'accepted' && !$session['approves']): ?>
                    <div class="card-actions">
                        <form method="post" action="<?= e(url('/collabs/session/answer')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
                            <input type="hidden" name="agree" value="1">
                            <button type="submit" class="btn primary"><?= e(__('ui.action.together_agree')) ?></button>
                        </form>
                        <form method="post" action="<?= e(url('/collabs/session/answer')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
                            <input type="hidden" name="agree" value="0">
                            <button type="submit" class="btn"><?= e(__('ui.action.together_reject')) ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($me === 'accepted' && $session['status'] === 'active'): ?>
            <form method="post" action="<?= e(url('/collabs/session/propose')) ?>" class="together-propose">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
                <label>
                    <span><?= e(__('ui.field.together_start')) ?></span>
                    <input type="datetime-local" name="start" required
                           value="<?= e($session['agreed_start'] ? (new DateTimeImmutable($session['agreed_start']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i') : '') ?>">
                </label>
                <label>
                    <span><?= e(__('ui.field.together_minutes')) ?></span>
                    <span class="input-suffix">
                        <input type="number" name="minutes" min="<?= ContentDefaults::MIN_MINUTES ?>" max="<?= ContentDefaults::MAX_MINUTES ?>" step="5"
                               value="<?= (int) ($session['agreed_minutes'] ?: ContentDefaults::minutes($userId)) ?>">
                        <span class="muted"><?= e(__('ui.label.unit_minutes')) ?></span>
                    </span>
                </label>
                <button type="submit" class="btn"><?= e(__(count($taking) > 1 ? 'ui.action.together_propose' : 'ui.action.together_set_time')) ?></button>
            </form>
            <p class="muted small"><?= e(__('ui.message.together_propose_hint')) ?></p>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <h2><?= e(__('ui.label.together_who')) ?></h2>
        </div>
        <ul class="together-members">
            <?php foreach ($session['members'] as $m): $avatarUser = $m; $avatarSize = 'sm'; ?>
                <li class="state-<?= e($m['state']) ?>">
                    <?php require dirname(__DIR__) . '/partials/avatar.php'; ?>
                    <span class="together-member-name">
                        <strong><?= e($m['name']) ?></strong>
                        <?php if ($m['twitch_login']): ?><small class="muted">@<?= e($m['twitch_login']) ?></small><?php endif; ?>
                    </span>
                    <?php if ($m['role'] === 'host'): ?><span class="badge"><?= e(__('ui.label.together_host')) ?></span><?php endif; ?>
                    <span class="badge <?= $m['state'] === 'accepted' ? 'ok' : ($m['state'] === 'invited' ? 'warn' : 'off') ?>"><?= e(__('ui.together_state.' . $m['state'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<?php if ($session['own']): ?>
    <section class="card">
        <div class="card-head">
            <h2><?= e(__('ui.label.together_yours')) ?></h2>
            <a class="btn small" href="<?= e(url('/content')) ?>"><?= e(__('ui.action.together_open_content')) ?></a>
        </div>
        <p class="together-own-title"><?= e($session['own']['title']) ?></p>
        <p class="muted small"><?= e(__('ui.message.together_yours_hint')) ?></p>
    </section>
<?php endif; ?>

<?php if ($session['status'] === 'active' && in_array($me, ['accepted', 'invited'], true) && !($me === 'invited')): ?>
    <form method="post" action="<?= e(url('/collabs/session/leave')) ?>" class="together-leave">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
        <button type="submit" class="btn small danger-btn"
                data-confirm="<?= e(__($isHost ? 'ui.message.together_cancel_confirm' : 'ui.message.together_leave_confirm')) ?>"><?= e(__($isHost ? 'ui.action.together_cancel' : 'ui.action.together_leave')) ?></button>
    </form>
<?php endif; ?>
