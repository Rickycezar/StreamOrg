<?php
/** @var array $user @var array $platforms @var array $locales @var array $timezones
 *  @var ?array $twitch @var bool $twitchConfigured */
?>
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
                            <?= e($loc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
