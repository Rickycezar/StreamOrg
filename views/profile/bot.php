<?php
/**
 * Profile → Chat bot: the streamer adds the bot to their channel and makes
 * its commands their own (or keeps the defaults).
 *
 * @var array $user @var array $account @var ?array $channel @var ?array $twitch
 * @var array<string, array> $commands @var array $stats @var bool $granted @var list<array> $log
 */
$active = $channel !== null && $channel['is_enabled'] && !$channel['is_blocked'];
$bot    = (string) $account['twitch_login'];
?>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.chat_bot')) ?></h2>
        <?php if ($channel !== null && $channel['is_blocked']): ?>
            <span class="badge warn"><?= e(__('ui.label.bot_blocked')) ?></span>
        <?php else: ?>
            <span class="badge <?= $active ? 'ok' : 'off' ?>"><?= e(__($active ? 'ui.label.bot_active' : 'ui.label.bot_not_added')) ?></span>
        <?php endif; ?>
    </div>
    <p class="muted"><?= e(sprintf(__('ui.message.bot_profile_intro'), $bot)) ?></p>

    <?php if ($twitch === null): ?>
        <p class="notice"><?= e(__('ui.message.bot_needs_twitch')) ?> <a href="<?= e(url('/profile')) ?>"><?= e(__('ui.label.profile_details')) ?></a></p>
    <?php elseif ($channel !== null && $channel['is_blocked']): ?>
        <p class="notice"><?= e(__('ui.message.bot_blocked_explain')) ?></p>
    <?php else: ?>
        <?php if ($active): ?>
            <p class="small">
                <?php if ($channel['joined_at']): ?>
                    <?= e(sprintf(__('ui.message.bot_in_channel'), $bot, $twitch['twitch_login'], fmt_datetime($channel['joined_at']))) ?>
                <?php else: ?>
                    <?= e(sprintf(__('ui.message.bot_joining'), $bot, $twitch['twitch_login'])) ?>
                <?php endif; ?>
                <?php if ($channel['last_error']): ?><br><span class="muted"><?= e($channel['last_error']) ?></span><?php endif; ?>
            </p>
            <p class="muted small"><?= e(sprintf(__('ui.message.bot_try_it'), $account['command_prefix'] . ($commands['heartbeat']['trigger'] ?? 'heartbeat'))) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/profile/bot')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="enabled" value="<?= $active ? '0' : '1' ?>">
            <button type="submit" class="btn <?= $active ? '' : 'primary' ?>"><?= e(sprintf(__($active ? 'ui.action.bot_remove' : 'ui.action.bot_add'), $bot)) ?></button>
        </form>
    <?php endif; ?>
</section>

<?php if ($active && $twitch !== null): ?>
    <section class="card">
        <div class="card-head">
            <h2><?= e(__('ui.label.bot_how_it_reads')) ?></h2>
            <span><?= View::partial('bot/access', ['access' => (string) $channel['access'], 'chattersOk' => $channel['chatters_ok']]) ?></span>
        </div>
        <p class="muted small"><?= e(sprintf(__('ui.message.bot_access_intro'), $bot)) ?></p>

        <div class="bot-ways">
            <div class="bot-way<?= $granted ? ' done' : '' ?>">
                <h3><?= e(__('ui.label.bot_way_permission')) ?></h3>
                <p class="small"><?= e(__('ui.message.bot_way_permission')) ?></p>
                <?php if ($granted): ?>
                    <span class="badge ok"><?= e(__('ui.label.bot_permission_granted')) ?></span>
                <?php else: ?>
                    <a class="btn small primary" href="<?= e(url('/profile/twitch/connect')) ?>" data-turbo="false"><?= e(__('ui.action.bot_grant_permission')) ?></a>
                <?php endif; ?>
            </div>
            <div class="bot-way<?= $channel['access'] === 'moderator' ? ' done' : '' ?>">
                <h3><?= e(__('ui.label.bot_way_moderator')) ?></h3>
                <p class="small"><?= e(__('ui.message.bot_way_moderator')) ?></p>
                <code>/mod <?= e($bot) ?></code>
            </div>
        </div>
        <p class="muted small"><?= e(__('ui.message.bot_access_recheck')) ?></p>
    </section>

    <section class="card">
        <div class="card-head">
            <h2><?= e(__('ui.label.bot_stats')) ?></h2>
        </div>
        <p class="muted small"><?= e(__('ui.message.bot_stats_streamer')) ?></p>
        <?= View::partial('bot/stats', ['stats' => $stats]) ?>
    </section>
<?php endif; ?>

<section>
    <h2><?= e(__('ui.label.bot_commands')) ?></h2>
    <p class="muted small"><?= e(__('ui.message.bot_commands_hint')) ?></p>
    <?php foreach ($commands as $code => $command): ?>
        <?= View::partial('bot/command_form', [
            'action'   => '/profile/bot/command',
            'code'     => $code,
            'command'  => $command,
            'prefix'   => (string) $account['command_prefix'],
            'personal' => (bool) $command['personal'],
            'streamer' => true,
        ]) ?>
    <?php endforeach; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.bot_activity')) ?></h2>
    </div>
    <?= View::partial('bot/log', ['log' => $log, 'showUser' => false]) ?>
</section>
