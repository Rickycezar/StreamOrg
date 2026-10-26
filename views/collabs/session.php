<?php
/**
 * A collab planned together, as one participant sees it: a header with
 * the shared time and where things stand, what is waiting for this
 * participant (the invitation, or a new time to answer), and one card per
 * participant with the content they plan for it. The time is changed in
 * a small window, as a proposal when others take part.
 *
 * @var array $session see CollabSessions::show() @var int $userId
 */
$me       = $session['member_state'];
$isHost   = $session['member_role'] === 'host';
$active   = $session['status'] === 'active';
$draft    = $session['status'] === 'draft';
$taking   = array_values(array_filter($session['members'], static fn (array $m): bool => $m['state'] === 'accepted'));
$agreedBy = count(array_filter($taking, static fn (array $m): bool => (bool) $m['approves']));
$pending  = count(array_filter($session['members'], static fn (array $m): bool => $m['state'] === 'invited'));
$proposal = $session['proposed_at'] !== null;
$proposer = null;
$zone     = new DateTimeZone(date_default_timezone_get());
$local    = static fn (string $at): DateTimeImmutable => (new DateTimeImmutable($at))->setTimezone($zone);
$length   = static fn (?int $minutes): string => $minutes ? sprintf(__('ui.label.together_length'), intdiv($minutes, 60), $minutes % 60) : '';

foreach ($session['members'] as $m) {
    if ((int) $m['user_id'] === (int) $session['proposed_by']) {
        $proposer = $m['name'];
    }
}

/** "Sat, 17 October · 19:00–21:00" */
$when = static function (?string $start, ?int $minutes) use ($local): string {
    if ($start === null) {
        return __('ui.label.together_no_time');
    }

    $at   = $local($start);
    $date = sprintf(__('ui.dashboard.date_format'), __('ui.weekday.' . $at->format('N')), (int) $at->format('j'), __('ui.month.' . $at->format('n')));

    return $date . ' · ' . $at->format('H:i') . ($minutes ? '–' . $at->modify('+' . $minutes . ' minutes')->format('H:i') : '');
};

/** "today", "tomorrow", "in 3 days", "3 days ago" */
$relative = static function (?string $start) use ($local, $zone): string {
    if ($start === null) {
        return '';
    }

    $days = (int) (new DateTimeImmutable('today', $zone))->diff($local($start)->setTime(0, 0))->format('%r%a');

    return match (true) {
        $days === 0  => __('ui.label.together_rel_today'),
        $days === 1  => __('ui.label.together_rel_tomorrow'),
        $days === -1 => __('ui.label.together_rel_yesterday'),
        $days > 1    => sprintf(__('ui.label.together_rel_in'), $days),
        default      => sprintf(__('ui.label.together_rel_ago'), -$days),
    };
};

[$statusClass, $statusLabel] = match (true) {
    $draft                            => ['warn', __('ui.label.together_status_draft')],
    !$active                          => ['off', __('ui.label.together_status_cancelled')],
    $me === 'invited'                 => ['warn', __('ui.label.together_status_invited')],
    $proposal                         => ['warn', sprintf(__('ui.label.together_status_waiting'), $agreedBy, count($taking))],
    $session['agreed_start'] === null => ['', __('ui.label.together_no_time')],
    $pending > 0                      => ['warn', sprintf(__('ui.label.together_status_pending'), $pending)],
    default                           => ['ok', __('ui.label.together_status_agreed')],
};

$heroArt = $session['own']['art'] ?? null;
foreach ($session['plans'] as $plan) {
    $heroArt = $heroArt ?? $plan['art'];
}
?>
<section class="card together-hero"<?= $heroArt ? ' data-row-art style="--row-art: url(&quot;' . e($heroArt) . '&quot;)"' : '' ?>>
    <div class="together-hero-main">
        <a class="together-back" href="<?= e(url('/collabs')) ?>">← <?= e(__('ui.nav.collabs')) ?></a>
        <h1><?= e($session['title']) ?></h1>
        <p class="together-hero-when">
            <strong><?= e($when($session['agreed_start'], $session['agreed_start'] ? (int) $session['agreed_minutes'] : null)) ?></strong>
            <?php if ($session['agreed_start']): ?>
                <span class="muted">· <?= e($length((int) $session['agreed_minutes'])) ?> · <?= e($relative($session['agreed_start'])) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="together-hero-side">
        <span class="badge together-status <?= e($statusClass) ?>" title="<?= e(__('ui.message.together_intro')) ?>"><?= e($statusLabel) ?></span>
        <span class="together-hero-actions">
            <?php if ($me === 'accepted' && $active): ?>
                <button type="button" class="btn" data-modal-form="#together-time"
                        data-modal-title="<?= e(__(count($taking) > 1 ? 'ui.action.together_change_time' : 'ui.action.together_set_time')) ?>">
                    <?= e(__(count($taking) > 1 ? 'ui.action.together_change_time' : 'ui.action.together_set_time')) ?>
                </button>
            <?php endif; ?>
            <?php $helpPage = 'together'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
        </span>
    </div>
</section>

<?php if (!$active && !$draft): ?>
    <p class="notice"><?= e(__('ui.message.together_was_cancelled')) ?></p>
<?php endif; ?>

<?php if ($draft): ?>
    <section class="card together-needs together-draft">
        <form method="post" action="<?= e(url('/collabs/session/send')) ?>" class="content-form together-send">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
            <span class="together-needs-label"><?= e(__('ui.label.together_draft_title')) ?></span>
            <p class="muted"><?= e(__('ui.message.together_draft_text')) ?></p>
            <?= View::partial('content/when_fields', [
                'start'    => $session['agreed_start'] ? $local($session['agreed_start'])->format('Y-m-d\TH:i') : '',
                'minutes'  => (int) ($session['agreed_minutes'] ?: ContentDefaults::minutes($userId)),
                'deadline' => null,
                'streamId' => $session['own'] ? (int) $session['own']['id'] : null,
                'required' => true,
                'names'    => ['start' => 'start', 'minutes' => 'minutes'],
            ]) ?>
            <label>
                <span><?= e(__('ui.label.together_draft_message')) ?></span>
                <textarea name="message" rows="3" maxlength="<?= CollabSessions::MESSAGE_MAX ?>"
                          placeholder="<?= e(__('ui.label.together_draft_placeholder')) ?>"></textarea>
            </label>
            <button type="submit" class="btn primary"><?= icon_label('send', __('ui.action.together_send_invites')) ?></button>
        </form>
    </section>
<?php elseif ($me === 'invited' && $active): ?>
    <section class="card together-needs">
        <div class="together-needs-text">
            <span class="together-needs-label"><?= e(__('ui.label.together_needs_you')) ?></span>
            <h2><?= e(sprintf(__('ui.message.together_invite_title'), $session['members'][0]['name'] ?? '')) ?></h2>
            <p class="muted"><?= e(__('ui.message.together_invite_text')) ?></p>
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
<?php elseif ($proposal && $active): ?>
    <section class="card together-needs<?= $me === 'accepted' && !$session['approves'] ? '' : ' is-waiting' ?>">
        <div class="together-needs-text">
            <span class="together-needs-label"><?= e(__($me === 'accepted' && !$session['approves'] ? 'ui.label.together_needs_you' : 'ui.label.together_waiting_others')) ?></span>
            <h2><?= e(sprintf(__('ui.label.together_proposes'), (string) $proposer)) ?></h2>
            <p class="together-change">
                <span class="together-old"><?= e($when($session['agreed_start'], $session['agreed_start'] ? (int) $session['agreed_minutes'] : null)) ?></span>
                <span aria-hidden="true">→</span>
                <strong><?= e($when($session['proposed_start'], (int) $session['proposed_minutes'])) ?></strong>
            </p>
            <ul class="together-votes">
                <?php foreach ($taking as $m): $avatarUser = $m; $avatarSize = 'sm'; ?>
                    <li class="<?= $m['approves'] ? 'yes' : 'waiting' ?>" title="<?= e(__($m['approves'] ? 'ui.label.together_agreed' : 'ui.label.together_waiting')) ?>">
                        <?php require dirname(__DIR__) . '/partials/avatar.php'; ?>
                        <span><?= e($m['name']) ?></span>
                        <span class="together-vote-mark"><?= Icons::svg($m['approves'] ? 'check' : 'clock') ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
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
                    <button type="submit" class="btn"><?= e(__('ui.action.together_keep_time')) ?></button>
                </form>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="together-body<?= $draft ? ' no-chat' : '' ?>">
<div class="together-main">
<h2 class="together-section-title"><?= e(__('ui.label.together_plans')) ?></h2>
<div class="together-plans">
    <?php foreach ($session['members'] as $m):
        $plan  = $session['plans'][(int) $m['user_id']] ?? null;
        $mine  = (int) $m['user_id'] === $userId;
        $gone  = in_array($m['state'], ['declined', 'left'], true);
        $avatarUser = $m; $avatarSize = 'sm'; ?>
        <article class="card plan-card state-<?= e($m['state']) ?><?= $mine ? ' is-mine' : '' ?>">
            <img class="plan-card-art" alt="" loading="lazy" src="<?= e($plan['art'] ?? url('/assets/game-missing.svg')) ?>">
            <header class="plan-card-head">
                <?php require dirname(__DIR__) . '/partials/avatar.php'; ?>
                <span class="plan-card-who">
                    <strong><?= e($m['name']) ?><?php if ($mine): ?> <small class="muted">(<?= e(__('ui.label.together_you')) ?>)</small><?php endif; ?></strong>
                    <?php if ($m['twitch_login']): ?><small class="muted">@<?= e($m['twitch_login']) ?></small><?php endif; ?>
                </span>
                <span class="plan-card-badges">
                    <?php if ($m['role'] === 'host'): ?><span class="badge"><?= e(__('ui.label.together_host')) ?></span><?php endif; ?>
                    <?php if (!($draft && $m['state'] === 'invited')): ?>
                        <span class="badge <?= $m['state'] === 'accepted' ? 'ok' : ($m['state'] === 'invited' ? 'warn' : 'off') ?>"><?= e(__('ui.together_state.' . $m['state'])) ?></span>
                    <?php endif; ?>
                </span>
            </header>
            <?php if ($plan !== null && !$gone): ?>
                <div class="plan-card-body">
                    <small class="plan-card-game"><?= e($plan['games'] ?: __('ui.dashboard.no_games')) ?></small>
                    <p class="plan-card-title"><?= e($plan['title']) ?></p>
                </div>
                <?php if ($mine): ?>
                    <footer class="plan-card-foot">
                        <span class="muted small"><?= e(__('ui.message.together_yours_hint')) ?></span>
                        <a class="btn small primary" href="<?= e(url('/content?edit=' . (int) $plan['id'])) ?>"><?= e(__('ui.action.edit')) ?></a>
                    </footer>
                <?php endif; ?>
            <?php else: ?>
                <p class="plan-card-empty muted"><?= e(__($draft ? 'ui.label.together_not_sent' : ($m['state'] === 'invited' ? 'ui.label.together_not_answered' : 'ui.label.together_no_plan'))) ?></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>

</div>

<?php if (!$draft): ?>
<section class="card together-chat" data-together-chat data-id="<?= (int) $session['id'] ?>">
    <div class="card-head">
        <h2><?= e(__('ui.label.together_chat')) ?></h2>
    </div>
    <div class="chat-log" data-chat-log aria-live="polite">
        <p class="chat-empty muted small" data-chat-empty><?= e(__('ui.label.together_chat_empty')) ?></p>
    </div>
    <?php if ($me === 'accepted' && $active): ?>
        <form class="chat-form" data-chat-form>
            <textarea name="body" rows="1" maxlength="<?= CollabSessions::MESSAGE_MAX ?>" required
                      placeholder="<?= e(__('ui.label.together_chat_placeholder')) ?>" title="<?= e(__('ui.label.together_chat_keys')) ?>"
                      aria-label="<?= e(__('ui.label.together_chat')) ?>"></textarea>
            <button type="submit" class="btn primary small"><?= icon_label('send', __('ui.action.send')) ?></button>
        </form>
    <?php else: ?>
        <p class="muted small chat-readonly"><?= e(__($draft ? 'ui.label.together_chat_draft' : ($active ? 'ui.label.together_chat_join' : 'ui.label.together_chat_closed'))) ?></p>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>

<?php if ($me === 'accepted' && $active): ?>
    <form id="together-time" method="post" action="<?= e(url('/collabs/session/propose')) ?>" class="subform hidden content-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
        <p class="muted small"><?= e(__(count($taking) > 1 ? 'ui.message.together_change_hint' : 'ui.message.together_set_hint')) ?></p>
        <?= View::partial('content/when_fields', [
            'start'    => $session['agreed_start'] ? $local($session['agreed_start'])->format('Y-m-d\TH:i') : '',
            'minutes'  => (int) ($session['agreed_minutes'] ?: ContentDefaults::minutes($userId)),
            'deadline' => null,
            'streamId' => $session['own'] ? (int) $session['own']['id'] : null,
            'required' => true,
            'names'    => ['start' => 'start', 'minutes' => 'minutes'],
        ]) ?>
        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__(count($taking) > 1 ? 'ui.action.together_propose' : 'ui.action.together_set_time')) ?></button>
            <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
        </div>
    </form>
<?php endif; ?>

<?php if (($active || $draft) && $me === 'accepted'):
    $leaveText  = $draft ? 'ui.message.together_discard_confirm' : ($isHost ? 'ui.message.together_cancel_confirm' : 'ui.message.together_leave_confirm');
    $leaveLabel = $draft ? 'ui.action.together_discard' : ($isHost ? 'ui.action.together_cancel' : 'ui.action.together_leave'); ?>
    <form method="post" action="<?= e(url('/collabs/session/leave')) ?>" class="together-leave">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
        <span class="muted small"><?= e(__($leaveText)) ?></span>
        <button type="submit" class="btn small ghost danger-link" data-confirm="<?= e(__($leaveText)) ?>"><?= e(__($leaveLabel)) ?></button>
    </form>
<?php endif; ?>
