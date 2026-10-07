<?php
/** @var array $user @var array $platforms @var array $locales @var array $timezones
 *  @var ?array $twitch @var bool $twitchConfigured
 *  @var bool $trackingAvailable @var bool $trackingActive @var array $trackingLog @var ?string $avatar */

$twitchSource = $twitch !== null || (($user['channel_handle'] ?? '') !== '' && Twitch::isConfigured());
?>
<section class="card avatar-card">
    <h2><?= e(__('ui.label.avatar')) ?></h2>
    <div class="avatar-edit">
        <?php $avatarUser = $user + ['avatar_url' => $avatar]; $avatarSize = 'xl'; require dirname(__DIR__) . '/partials/avatar.php'; ?>
        <div class="avatar-actions">
            <form method="post" action="<?= e(url('/profile/avatar')) ?>" enctype="multipart/form-data" class="avatar-upload">
                <?= Csrf::field() ?>
                <label class="btn">
                    <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif" required data-autosubmit-file>
                    <?= e(__($avatar ? 'ui.action.avatar_change' : 'ui.action.avatar_upload')) ?>
                </label>
                <noscript><button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button></noscript>
            </form>
            <?php if ($twitchSource): ?>
                <form method="post" action="<?= e(url('/profile/avatar/twitch')) ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn twitch-btn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3h16v11l-4 4h-4l-3 3v-3H4zM10 8v4M15 8v4"/></svg>
                        <?= e(__('ui.action.avatar_twitch')) ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($avatar): ?>
                <form method="post" action="<?= e(url('/profile/avatar/remove')) ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn ghost"><?= e(__('ui.action.avatar_remove')) ?></button>
                </form>
            <?php endif; ?>
            <p class="muted small"><?= e(__('ui.message.avatar_hint')) ?></p>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.twitch_connection')) ?></h2>
        <span class="badge <?= $twitch ? 'ok' : 'off' ?>">
            <?= e($twitch ? __('ui.label.connected') : __('ui.label.not_connected')) ?>
        </span>
    </div>

    <?php if ($twitch): ?>
        <p><?= e(sprintf(__('ui.message.twitch_connected_as'), $twitch['twitch_login'], fmt_datetime($twitch['connected_at']))) ?></p>
        <p class="muted small"><?= e(__('ui.message.twitch_connection_explain')) ?></p>
        <form method="post" action="<?= e(url('/profile/twitch/disconnect')) ?>">
            <?= Csrf::field() ?>
            <button type="submit" class="btn" data-confirm="<?= e(__('ui.message.twitch_confirm_disconnect')) ?>">
                <?= e(__('ui.action.twitch_disconnect')) ?>
            </button>
        </form>

        <div class="tracking">
            <div class="tracking-head">
                <h3><?= e(__('ui.label.live_tracking')) ?></h3>
                <span class="badge <?= $trackingActive ? 'ok' : 'off' ?>">
                    <?= e(__($trackingActive ? 'ui.label.tracking_on' : 'ui.label.tracking_off')) ?>
                </span>
            </div>
            <p class="muted small"><?= e(__('ui.message.tracking_explain')) ?></p>

            <?php if ($trackingAvailable || $trackingActive): ?>
                <form method="post" action="<?= e(url('/profile/twitch/tracking')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="tracking" value="<?= $trackingActive ? 'off' : 'on' ?>">
                    <button type="submit" class="btn<?= $trackingActive ? '' : ' primary' ?>">
                        <?= e(__($trackingActive ? 'ui.action.tracking_disable' : 'ui.action.tracking_enable')) ?>
                    </button>
                </form>
            <?php else: ?>
                <p class="empty"><?= e(__('ui.message.tracking_unavailable')) ?></p>
            <?php endif; ?>

            <?php if ($trackingLog !== []): ?>
                <h3 class="section-head"><?= e(__('ui.label.tracking_log')) ?></h3>
                <ul class="tracking-log">
                    <?php foreach ($trackingLog as $entry): ?>
                        <li class="kind-<?= e($entry['kind']) ?>">
                            <span class="muted nowrap"><?= e(fmt_datetime($entry['at'], 'd/m H:i')) ?></span>
                            <span><?= e(code_label('live_event', $entry['kind'])) ?><?= $entry['detail'] !== null ? ': ' : '' ?><b><?= e((string) $entry['detail']) ?></b></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php elseif ($twitchConfigured): ?>
        <p class="muted small"><?= e(__('ui.message.twitch_connection_explain')) ?></p>
        <a class="btn primary" href="<?= e(url('/profile/twitch/connect')) ?>" data-turbo="false"><?= e(__('ui.action.twitch_connect')) ?></a>
    <?php else: ?>
        <p class="empty"><?= e(__('ui.message.twitch_not_configured')) ?></p>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= e(__('ui.label.your_details')) ?></h2>

    <form method="post" action="<?= e(url('/profile')) ?>" class="subform">
        <?= Csrf::field() ?>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.username')) ?></span>
                <input type="text" value="<?= e($user['username']) ?>" disabled>
                <small class="muted"><?= e(__('ui.label.username_fixed')) ?></small>
            </label>
            <label class="grow">
                <span><?= e(__('ui.field.name')) ?></span>
                <input type="text" name="display_name" value="<?= e($user['display_name'] ?? '') ?>">
            </label>
        </div>

        <label>
            <span><?= e(__('ui.field.email')) ?></span>
            <input type="email" name="email" value="<?= e($user['email']) ?>" required>
        </label>

        <div class="grid">
            <label>
                <span><?= e(__('ui.field.platform')) ?></span>
                <select name="channel_platform">
                    <option value=""><?= e(__('ui.label.none')) ?></option>
                    <?php foreach ($platforms as $p): ?>
                        <option value="<?= e($p['code']) ?>"
                            <?= (int) $user['channel_platform_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e(code_label('streaming_platform', $p['code'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="grow">
                <span><?= e(__('ui.field.handle')) ?></span>
                <input type="text" name="channel_handle" value="<?= e($user['channel_handle'] ?? '') ?>"
                       autocomplete="off">
            </label>
        </div>

        <div class="grid">
            <label>
                <span><?= e(__('ui.field.language')) ?></span>
                <select name="locale">
                    <?php foreach ($locales as $loc): ?>
                        <option value="<?= e($loc) ?>" <?= $user['locale'] === $loc ? 'selected' : '' ?>>
                            <?= e(Lang::t('ui.label.language_name', $loc)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!Lang::switchable()): ?>
                    <small class="muted"><?= e(sprintf(__('ui.message.language_fixed_here'), (string) preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''))), Lang::t('ui.label.language_name', Lang::locale()))) ?></small>
                <?php endif; ?>
            </label>
            <label class="grow">
                <span><?= e(__('ui.field.timezone')) ?></span>
                <select name="timezone">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?= e($tz) ?>" <?= $user['timezone'] === $tz ? 'selected' : '' ?>>
                            <?= e($tz) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(__('ui.label.account')) ?></h2>
    <dl class="detail">
        <dt><?= e(__('ui.field.role')) ?></dt>
        <dd><?= e(code_label('user_role', $user['role'])) ?></dd>
        <dt><?= e(__('ui.field.created_at')) ?></dt>
        <dd><?= e(fmt_datetime($user['created_at'])) ?></dd>
        <dt><?= e(__('ui.label.last_login')) ?></dt>
        <dd><?= e($user['last_login_at'] ? fmt_datetime($user['last_login_at']) : '—') ?></dd>
    </dl>
</section>
