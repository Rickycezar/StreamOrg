<?php
/**
 * Administration → Chat bot: the account it speaks as, whether it runs,
 * the default commands, the channels that added it, and recent activity.
 *
 * @var array $account @var array<string, array> $defaults @var list<array> $channels
 * @var list<array> $log @var bool $twitchConfigured
 */
$state = !empty($account['online']) ? (string) ($account['state'] ?? 'connected') : 'offline';
$tone  = match ($state) {
    'connected' => 'ok',
    'offline'   => 'off',
    default     => 'warn',
};
?>
<h1><?= e(__('ui.nav.chat_bot')) ?></h1>
<p class="muted"><?= e(__('ui.message.bot_intro')) ?></p>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.bot_account')) ?></h2>
        <span class="badge <?= $tone ?>"><?= e(__('ui.bot_state.' . $state)) ?></span>
    </div>

    <dl class="detail">
        <dt><?= e(__('ui.label.bot_service')) ?></dt>
        <dd>
            <?php if (!empty($account['seen_at'])): ?>
                <?= e(sprintf(__('ui.label.bot_seen'), fmt_datetime($account['seen_at']))) ?>
                <?php if (!empty($account['version'])): ?><span class="muted">· v<?= e($account['version']) ?></span><?php endif; ?>
                <?php if (!empty($account['state_detail'])): ?><br><small class="muted"><?= e($account['state_detail']) ?></small><?php endif; ?>
            <?php else: ?>
                <span class="muted"><?= e(__('ui.message.bot_never_seen')) ?></span>
            <?php endif; ?>
        </dd>
        <dt><?= e(__('ui.label.bot_twitch_account')) ?></dt>
        <dd>
            <?php if (!empty($account['twitch_login'])): ?>
                <a href="https://www.twitch.tv/<?= e(rawurlencode($account['twitch_login'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($account['twitch_login']) ?></a>
                <small class="muted">· <?= e(sprintf(__('ui.label.connected_since'), fmt_datetime($account['connected_at']))) ?></small>
            <?php else: ?>
                <span class="muted"><?= e(__('ui.message.bot_no_account')) ?></span>
            <?php endif; ?>
        </dd>
    </dl>

    <div class="card-actions">
        <?php if (!$twitchConfigured): ?>
            <p class="muted small"><?= e(__('ui.message.twitch_not_configured')) ?></p>
        <?php elseif (empty($account['twitch_login'])): ?>
            <a class="btn primary" href="<?= e(url('/admin/bot/connect')) ?>" data-turbo="false"><?= e(__('ui.action.bot_connect')) ?></a>
            <p class="muted small"><?= e(__('ui.message.bot_connect_hint')) ?></p>
        <?php else: ?>
            <a class="btn" href="<?= e(url('/admin/bot/connect')) ?>" data-turbo="false"><?= e(__('ui.action.bot_reconnect')) ?></a>
            <form method="post" action="<?= e(url('/admin/bot/disconnect')) ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn danger-btn" data-confirm="<?= e(__('ui.message.bot_disconnect_confirm')) ?>"><?= e(__('ui.action.disconnect')) ?></button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($account['twitch_login'])): ?>
        <form method="post" action="<?= e(url('/admin/bot/settings')) ?>" class="subform">
            <?= Csrf::field() ?>
            <div class="grid">
                <label class="inline">
                    <input type="checkbox" name="is_enabled" value="1" <?= !empty($account['is_enabled']) ? 'checked' : '' ?>>
                    <span><?= e(__('ui.field.bot_enabled')) ?> <small class="muted"><?= e(__('ui.label.bot_enabled_hint')) ?></small></span>
                </label>
                <label>
                    <span><?= e(__('ui.field.bot_prefix')) ?></span>
                    <input type="text" name="command_prefix" value="<?= e($account['command_prefix']) ?>" required maxlength="2" pattern="[!?.#$%&amp;*+~\-]{1,2}">
                </label>
            </div>
            <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
        </form>
    <?php endif; ?>
</section>

<section>
    <h2><?= e(__('ui.label.bot_default_commands')) ?></h2>
    <p class="muted small"><?= e(__('ui.message.bot_defaults_hint')) ?></p>
    <?php foreach ($defaults as $code => $command): ?>
        <?= View::partial('bot/command_form', [
            'action'   => '/admin/bot/command',
            'code'     => $code,
            'command'  => $command,
            'prefix'   => (string) $account['command_prefix'],
            'personal' => false,
            'streamer' => false,
        ]) ?>
    <?php endforeach; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.bot_channels')) ?> (<?= count(array_filter($channels, static fn (array $c): bool => (bool) $c['is_enabled'])) ?>)</h2>
    </div>
    <p class="muted small"><?= e(__('ui.message.bot_channels_hint')) ?></p>
    <?php if ($channels === []): ?>
        <p class="empty"><?= e(__('ui.message.bot_no_channels')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('ui.field.channel')) ?></th>
                    <th><?= e(__('ui.field.user')) ?></th>
                    <th><?= e(__('ui.field.status')) ?></th>
                    <th><?= e(__('ui.label.bot_joined')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($channels as $channel):
                    $status = match (true) {
                        (bool) $channel['is_blocked']        => ['warn', 'ui.label.bot_blocked'],
                        !$channel['added']                   => ['off', 'ui.label.bot_not_added'],
                        !$channel['is_enabled']              => ['off', 'ui.label.bot_removed'],
                        $channel['twitch_login'] === null    => ['warn', 'ui.label.bot_no_twitch'],
                        $channel['joined_at'] === null       => ['warn', 'ui.label.bot_waiting'],
                        default                              => ['ok', 'ui.label.bot_active'],
                    }; ?>
                    <tr>
                        <td><?= $channel['twitch_login'] ? e('#' . $channel['twitch_login']) : '—' ?></td>
                        <td><?= e($channel['display_name'] ?: $channel['username']) ?></td>
                        <td>
                            <span class="badge <?= $status[0] ?>"><?= e(__($status[1])) ?></span>
                            <?php if ($channel['last_error']): ?><br><small class="muted"><?= e($channel['last_error']) ?></small><?php endif; ?>
                        </td>
                        <td><?= $channel['joined_at'] ? e(fmt_datetime($channel['joined_at'])) : '—' ?></td>
                        <td class="rowactions">
                            <?php if ($channel['is_enabled'] || $channel['twitch_login'] !== null): ?>
                                <form method="post" action="<?= e(url('/admin/bot/add')) ?>">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="user_id" value="<?= (int) $channel['user_id'] ?>">
                                    <input type="hidden" name="enabled" value="<?= $channel['is_enabled'] ? '0' : '1' ?>">
                                    <button type="submit" class="btn small<?= $channel['is_enabled'] ? '' : ' primary' ?>"<?= $channel['is_blocked'] && !$channel['is_enabled'] ? ' disabled' : '' ?>><?= e(__($channel['is_enabled'] ? 'ui.action.bot_remove_channel' : 'ui.action.bot_add_channel')) ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($channel['added']): ?>
                            <form method="post" action="<?= e(url('/admin/bot/channel')) ?>">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="user_id" value="<?= (int) $channel['user_id'] ?>">
                                <input type="hidden" name="blocked" value="<?= $channel['is_blocked'] ? '0' : '1' ?>">
                                <button type="submit" class="btn small<?= $channel['is_blocked'] ? '' : ' danger-btn' ?>"><?= e(__($channel['is_blocked'] ? 'ui.action.unblock' : 'ui.action.block')) ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.bot_activity')) ?></h2>
    </div>
    <?= View::partial('bot/log', ['log' => $log, 'showUser' => true]) ?>
</section>
