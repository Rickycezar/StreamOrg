<?php
/**
 * Administration → Overview: what needs an administrator now, how
 * StreamOrg is being used, the newest accounts and the latest things
 * people added, and every administration page grouped by subject (the
 * same groups as the menu), with its count where it has one.
 *
 * @var array $attention @var array $activity @var list<array> $accounts @var list<array> $latest @var array<string,Provider> $providers
 */
$mb = static fn (int $bytes): string => number_format($bytes / 1048576, 1);
$botLabel = __('ui.admin_overview.bot_' . (in_array($attention['bot'], ['none', 'off', 'offline', 'connected'], true) ? $attention['bot'] : 'other'));
$tile = static function (string $href, int|string $value, string $label, string $level = '') {
    return '<a class="tile' . ($level !== '' ? ' tile-' . $level : '') . (is_string($value) && !preg_match('/^[\d.\/]+$/', $value) ? ' tile-text' : '') . '" href="' . e(url($href)) . '"><span class="tile-value">' . e((string) $value) . '</span><span class="tile-label">' . e($label) . '</span></a>';
};
$groups = [
    'ui.nav.admin_people'    => ['/admin/users' => ['ui.nav.users', $activity['accounts']], '/admin/data' => ['ui.admin_data.title', null]],
    'ui.nav.catalog'         => ['/admin/games' => ['ui.nav.games', $activity['games']], '/admin/publishers' => ['ui.nav.publishers', $activity['publishers']],
                                 '/admin/developers' => ['ui.nav.developers', $activity['developers']], '/admin/key-sites' => ['ui.nav.key_sites', $activity['key_sites']],
                                 '/admin/import' => ['ui.nav.import', count(array_filter($providers, static fn ($p) => $p->isAvailable())) . '/' . count($providers)]],
    'ui.nav.admin_community' => ['/admin/notifications' => ['ui.nav.notifications', null], '/admin/winners' => ['ui.nav.winners', null], '/admin/testimonials' => ['ui.nav.testimonials', null]],
    'ui.nav.stream_tools'    => ['/admin/bot' => ['ui.nav.chat_bot', $activity['bot_channels']], '/admin/overlays' => ['ui.nav.overlays', $activity['overlays']]],
    'ui.nav.support'         => ['/admin/bugs' => ['ui.nav.bug_tracker', null], '/admin/feedback' => ['ui.nav.feedback_inbox', null], '/admin/surveys' => ['ui.nav.surveys', null], '/admin/errors' => ['ui.nav.errors', null]],
    'ui.nav.admin_system'    => ['/admin/settings' => ['ui.nav.settings', null], '/admin/api' => ['ui.nav.api_settings', null], '/admin/lang' => ['ui.nav.languages', null]],
];
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.admin')) ?></h1>
    <span class="badge" title="<?= e(__('ui.label.app_version')) ?>">v<?= e(AppVersion::current()) ?></span>
</div>

<h2 class="section-title"><?= e(__('ui.admin_overview.attention')) ?></h2>
<section class="tiles admin-tiles">
    <?= $tile('/admin/errors', $attention['errors'], __('ui.admin_overview.errors'), $attention['errors'] > 0 ? 'danger' : '') ?>
    <?= $tile('/admin/errors', $attention['warnings'], __('ui.admin_overview.warnings'), $attention['warnings'] > 0 ? 'warn' : '') ?>
    <?= $tile('/admin/bugs', $attention['bugs_news'], __('ui.admin_overview.bugs_news'), $attention['bugs_news'] > 0 ? 'warn' : '') ?>
    <?= $tile('/admin/bugs', $attention['bugs_new'], __('ui.admin_overview.bugs_new'), $attention['bugs_new'] > 0 ? 'warn' : '') ?>
    <?= $tile('/admin/feedback', $attention['feedback'], __('ui.admin_overview.feedback'), $attention['feedback'] > 0 ? 'warn' : '') ?>
    <?= $tile('/admin/surveys', $attention['surveys'], __('ui.admin_overview.surveys')) ?>
    <?= $tile('/admin/bot', $botLabel, __('ui.nav.chat_bot'), in_array($attention['bot'], ['connected', 'none', 'off'], true) ? '' : 'warn') ?>
</section>

<h2 class="section-title"><?= e(__('ui.admin_overview.activity')) ?></h2>
<section class="tiles admin-tiles">
    <?= $tile('/admin/users', $activity['accounts'], __('ui.admin_overview.accounts')) ?>
    <?= $tile('/admin/users', $activity['active_week'], __('ui.admin_overview.active_week')) ?>
    <?= $tile('/admin/users', $activity['new_month'], __('ui.admin_overview.new_month')) ?>
    <?= $tile('/admin/data/content?status=planned', $activity['content_week'], __('ui.admin_overview.content_week')) ?>
    <?= $tile('/admin/bot', $activity['live_now'], __('ui.admin_overview.live_now')) ?>
    <?= $tile('/admin/data/keys', $activity['keys'], sprintf(__('ui.admin_overview.keys'), $activity['keys_available'])) ?>
    <?= $tile('/admin/winners', $activity['giveaways_open'], __('ui.admin_overview.giveaways_open')) ?>
    <?= $tile('/admin/data/collabs', $activity['collabs_open'], __('ui.admin_overview.collabs_open')) ?>
    <?= $tile('/admin/overlays', $activity['overlays_open'] . '/' . $activity['overlays'], __('ui.admin_overview.overlays_open')) ?>
    <?= $tile('/admin/overlays', $mb($activity['media_bytes']), __('ui.admin_overview.media_mb')) ?>
    <?= $tile('/admin/winners', $activity['viewers'], __('ui.admin_overview.viewers')) ?>
</section>

<div class="admin-columns">
    <section class="card">
        <div class="card-head">
            <h2><?= e(__('ui.admin_overview.newest_accounts')) ?></h2>
            <a class="btn small" href="<?= e(url('/admin/users')) ?>"><?= e(__('ui.nav.users')) ?></a>
        </div>
        <ul class="admin-list">
            <?php foreach ($accounts as $a): ?>
                <li>
                    <a href="<?= e(url('/admin/data/content?user=' . (int) $a['id'])) ?>"><strong><?= e($a['name']) ?></strong></a>
                    <?php if ($a['role'] === 'admin'): ?><span class="badge"><?= e(__('ui.admin_overview.admin')) ?></span><?php endif; ?>
                    <?php if ($a['twitch_login']): ?><small class="muted">twitch/<?= e($a['twitch_login']) ?></small><?php endif; ?>
                    <small class="muted block"><?= e(sprintf(__('ui.admin_overview.joined'), fmt_date($a['created_at']))) ?> · <?= e($a['last_seen'] ? sprintf(__('ui.admin_overview.last_seen'), fmt_datetime($a['last_seen'])) : __('ui.admin_overview.never_seen')) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card">
        <div class="card-head">
            <h2><?= e(__('ui.admin_overview.latest')) ?></h2>
            <a class="btn small" href="<?= e(url('/admin/data')) ?>"><?= e(__('ui.admin_data.title')) ?></a>
        </div>
        <?php if ($latest === []): ?>
            <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
        <?php else: ?>
            <ul class="admin-list">
                <?php foreach ($latest as $l): ?>
                    <li>
                        <span class="badge"><?= e(__('ui.admin_data.tab_' . $l['kind'])) ?></span>
                        <?= e($l['label']) ?><?= $l['items'] > 1 ? ' ×' . $l['items'] : '' ?>
                        <small class="muted block"><a href="<?= e(url('/admin/data/' . $l['kind'] . '?user=' . $l['user_id'])) ?>"><?= e($l['user_name']) ?></a> · <?= e(fmt_datetime($l['created_at'])) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<h2 class="section-title"><?= e(__('ui.admin_overview.everything')) ?></h2>
<section class="admin-groups">
    <?php foreach ($groups as $label => $links): ?>
        <div class="card admin-group">
            <h3><?= e(__($label)) ?></h3>
            <?php foreach ($links as $href => [$name, $count]): ?>
                <a href="<?= e(url($href)) ?>"><span><?= e(__($name)) ?></span><?php if ($count !== null): ?><small class="muted"><?= e((string) $count) ?></small><?php endif; ?></a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</section>
