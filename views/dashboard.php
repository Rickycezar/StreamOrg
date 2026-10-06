<?php
/** @var string $greeting @var string $name @var DateTimeImmutable $today @var array $todayItems
 *  @var ?array $streamDay @var bool $hasSchedule @var string $pickStart @var array $backlog
 *  @var array $upcoming @var array $attention @var ?string $twitchLogin @var ?array $onAir */

$zone  = new DateTimeZone(date_default_timezone_get());
$local = static fn (string $at): DateTimeImmutable => (new DateTimeImmutable($at))->setTimezone($zone);

$dateLabel = sprintf(
    __('ui.dashboard.date_format'),
    __('ui.weekday.' . $today->format('N')),
    (int) $today->format('j'),
    __('ui.month.' . $today->format('n'))
);

$shortcuts = [
    ['/content#new',   'ui.dashboard.new_content',  'M4 6h16v14H4zM4 10h16M9 3v4M15 3v4M12 13v4M10 15h4'],
    ['/keys#new',      'ui.dashboard.new_key',      'M14 10a4 4 0 1 0-3.9 4H11l1.5 1.5L14 14l1.5 1.5L17 14l2 2M7.5 10.5h.01'],
    ['/collabs#new',   'ui.dashboard.new_collab',   'M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM3 20c0-3 3-5 6-5s6 2 6 5M18 8v6M15 11h6'],
    ['/embargoes#new', 'ui.dashboard.new_embargo',  'M12 3l9 16H3zM12 10v4M12 17h.01'],
    ['/catalog',       'ui.dashboard.find_game',    'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM21 21l-5-5'],
];

$kindLabel = [
    'content'  => __('ui.nav.content'),
    'embargo'  => __('ui.field.embargo'),
    'deadline' => __('ui.field.deadline'),
    'expiry'   => __('ui.field.redeem_by'),
];
?>
<header class="dash-head">
    <div>
        <h1><?= e(sprintf(__('ui.dashboard.greeting_' . $greeting), $name)) ?></h1>
        <p class="muted"><?= e($dateLabel) ?></p>
    </div>
    <?php $helpPage = 'dashboard'; require __DIR__ . '/partials/help_link.php'; ?>
</header>

<nav class="shortcuts" aria-label="<?= e(__('ui.dashboard.shortcuts')) ?>">
    <?php foreach ($shortcuts as [$href, $label, $icon]): ?>
        <a class="shortcut" href="<?= e(url($href)) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= e($icon) ?>"/></svg>
            <span><?= e(__($label)) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<section class="card today">
    <div class="card-head">
        <h2><?= e(__('ui.dashboard.today_title')) ?></h2>
        <?php if ($onAir !== null): ?>
            <span class="today-onair"><span class="dot"></span><?= e(sprintf(__('ui.dashboard.on_air'), $onAir['category_name'] ?: '—')) ?></span>
        <?php endif; ?>
        <?php if ($todayItems !== [] && $backlog !== []): ?>
            <button type="button" class="btn small" data-modal-form="#pick-today"
                    data-modal-title="<?= e(__('ui.dashboard.pick_title')) ?>">+ <?= e(__('ui.dashboard.pick_more')) ?></button>
        <?php endif; ?>
    </div>

    <?php if ($todayItems === []): ?>
        <div class="day-off">
            <svg class="day-off-art" viewBox="0 0 120 90" aria-hidden="true">
                <circle cx="88" cy="26" r="13" class="sun"/>
                <path d="M10 74c14-8 30-8 44 0s30 8 44 0" class="wave"/>
                <path d="M18 84c12-6 26-6 38 0s26 6 38 0" class="wave soft"/>
                <path d="M40 66V30M40 30c-10 0-16 6-18 12M40 30c10 0 16 6 18 12M40 30c-6-6-14-6-18-4M40 30c6-6 14-6 18-4" class="palm"/>
            </svg>
            <h3><?= e(__('ui.dashboard.day_off_title')) ?></h3>
            <p class="muted"><?= e(__('ui.dashboard.day_off_text')) ?></p>
            <?php if ($streamDay !== null): ?>
                <p class="muted small"><?= e(sprintf(__('ui.dashboard.stream_day_hint'), $streamDay['start'])) ?></p>
            <?php elseif (!$hasSchedule): ?>
                <p class="muted small">
                    <a href="<?= e(url('/profile/defaults')) ?>"><?= e(__('ui.dashboard.set_schedule')) ?></a>
                </p>
            <?php endif; ?>
            <div class="day-off-actions">
                <?php if ($backlog !== []): ?>
                    <button type="button" class="btn primary" data-modal-form="#pick-today"
                            data-modal-title="<?= e(__('ui.dashboard.pick_title')) ?>"><?= e(__('ui.dashboard.pick_content')) ?></button>
                <?php endif; ?>
                <a class="btn<?= $backlog === [] ? ' primary' : '' ?>" href="<?= e(url('/content#new')) ?>"><?= e(__('ui.dashboard.new_content')) ?></a>
            </div>
        </div>
    <?php else: ?>
        <div class="today-grid" data-relative
             data-in="<?= e(__('ui.dashboard.starts_in')) ?>" data-ago="<?= e(__('ui.dashboard.started_ago')) ?>"
             data-now-label="<?= e(__('ui.dashboard.starting_now')) ?>">
            <?php foreach ($todayItems as $item):
                $start  = $local($item['scheduled_start']);
                $end    = $item['expected_end'];
                $status = $item['status'];
                $canPush = $twitchLogin !== null && $item['platform_code'] === 'twitch' && in_array($status, ['planned', 'live'], true); ?>
                <article class="today-card status-<?= e($status) ?>">
                    <button type="button" class="today-open content-detail" data-id="<?= (int) $item['id'] ?>">
                        <span class="today-art"<?= $item['art'] ? '' : ' data-empty' ?>>
                            <?php if ($item['art']): ?><img src="<?= e($item['art']) ?>" alt="" loading="lazy"><?php endif; ?>
                            <span class="today-time"><?= e($start->format('H:i')) ?></span>
                            <?php if ($status === 'live'): ?>
                                <span class="today-live"><span class="dot"></span><?= e(code_label('stream_status', 'live')) ?></span>
                            <?php elseif ($status !== 'planned'): ?>
                                <span class="badge today-status"><?= e(code_label('stream_status', $status)) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="today-body">
                            <strong><?= e($item['title']) ?></strong>
                            <small class="muted"><?= e($item['games'] ?: __('ui.dashboard.no_games')) ?></small>
                            <?php if ((int) $item['unkeyed'] > 0 || $item['breaks_embargo']): ?>
                                <span class="today-flags">
                                    <?php if ((int) $item['unkeyed'] > 0): ?><span class="badge warn"><?= e(__('ui.label.no_key')) ?></span><?php endif; ?>
                                    <?php if ($item['breaks_embargo']): ?><span class="badge danger"><?= e(__('ui.label.before_embargo')) ?></span><?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </button>
                    <footer class="today-foot">
                        <span class="today-when">
                            <span class="today-range"><?= e($start->format('H:i')) ?> – <?= e($end->format('H:i')) ?></span>
                            <?php if ($status === 'live' && $item['actual_start']): ?>
                                <small class="muted"><?= e(sprintf(__('ui.dashboard.live_since'), $local($item['actual_start'])->format('H:i'))) ?></small>
                            <?php elseif ($status === 'planned'): ?>
                                <small class="muted" data-start="<?= $start->getTimestamp() ?>"></small>
                            <?php endif; ?>
                        </span>
                        <?php if ($canPush): ?>
                            <span class="today-push">
                                <?php if ($item['twitch_pushed_at']): ?>
                                    <small class="muted today-sent" title="<?= e(sprintf(__('ui.dashboard.twitch_sent_at'), $twitchLogin)) ?>">
                                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"
                                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>
                                        <?= e($local($item['twitch_pushed_at'])->format('H:i')) ?>
                                    </small>
                                <?php endif; ?>
                                <button type="button" class="btn small twitch-push<?= $status === 'planned' ? ' primary' : '' ?>" data-id="<?= (int) $item['id'] ?>"
                                        title="<?= e(sprintf(__('ui.action.twitch_send_hint'), $twitchLogin)) ?>">
                                    <?= e(__($item['twitch_pushed_at'] ? 'ui.dashboard.twitch_resend' : 'ui.action.twitch_send')) ?>
                                </button>
                            </span>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<div class="chart-grid">
    <section class="card">
        <div class="card-head">
            <h2><?= e(__('ui.dashboard.next_days')) ?></h2>
            <a class="btn small ghost" href="<?= e(url('/content')) ?>"><?= e(__('ui.nav.content')) ?></a>
        </div>
        <?php if ($upcoming === []): ?>
            <p class="empty"><?= e(__('ui.dashboard.next_days_empty')) ?></p>
        <?php else: ?>
            <ul class="upcoming">
                <?php foreach ($upcoming as $row):
                    $at = $local($row['at']); ?>
                    <li>
                        <span class="upcoming-when">
                            <b><?= e(__('ui.weekday_short.' . $at->format('N'))) ?></b>
                            <?= e($at->format('d/m')) ?>
                        </span>
                        <span class="upcoming-what"><?= e(mb_strimwidth((string) $row['label'], 0, 60, '…')) ?></span>
                        <span class="badge kind-<?= e($row['kind']) ?>"><?= e($kindLabel[$row['kind']] ?? $row['kind']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2><?= e(__('ui.label.needs_attention')) ?></h2>
        <ul class="todo">
            <li class="<?= $attention['undated'] > 0 ? 'on' : '' ?>">
                <a href="<?= e(url('/content?dated=undated')) ?>">
                    <strong><?= (int) $attention['undated'] ?></strong>
                    <?= e(__('ui.label.todo_undated')) ?>
                </a>
            </li>
            <li class="<?= $attention['unkeyed'] > 0 ? 'on' : '' ?>">
                <a href="<?= e(url('/content?keyed=without')) ?>">
                    <strong><?= (int) $attention['unkeyed'] ?></strong>
                    <?= e(__('ui.label.todo_unkeyed')) ?>
                </a>
            </li>
            <li class="<?= $attention['conflicts'] > 0 ? 'on danger' : '' ?>">
                <a href="<?= e(url('/content')) ?>">
                    <strong><?= (int) $attention['conflicts'] ?></strong>
                    <?= e(__('ui.dashboard.todo_conflicts')) ?>
                </a>
            </li>
            <li class="<?= $attention['placeholders'] > 0 ? 'on' : '' ?>">
                <a href="<?= e(url('/keys')) ?>">
                    <strong><?= (int) $attention['placeholders'] ?></strong>
                    <?= e(__('ui.label.todo_placeholders')) ?>
                </a>
            </li>
        </ul>
    </section>
</div>

<?php if ($backlog !== []): ?>
<div id="pick-today" class="pick-today hidden" data-date="<?= e($today->format('Y-m-d')) ?>">
    <label class="pick-time">
        <span><?= e(__('ui.dashboard.pick_at')) ?></span>
        <input type="time" value="<?= e($pickStart) ?>" data-pick-time required>
    </label>
    <p class="muted small"><?= e(__('ui.dashboard.pick_hint')) ?></p>
    <ul class="pick-list">
        <?php foreach ($backlog as $item): ?>
            <li>
                <button type="button" class="pick-item" data-pick-id="<?= (int) $item['id'] ?>">
                    <?php if ($item['thumb']): ?>
                        <img class="picker-cover" src="<?= e($item['thumb']) ?>" alt="">
                    <?php else: ?>
                        <span class="picker-cover"></span>
                    <?php endif; ?>
                    <span class="pick-text">
                        <strong><?= e($item['title']) ?></strong>
                        <small class="muted"><?= e($item['games'] ?: __('ui.dashboard.no_games')) ?></small>
                    </span>
                    <?php if ($item['deadline']): ?>
                        <span class="badge"><?= e(__('ui.field.deadline')) ?> <?= e(fmt_date($item['deadline'])) ?></span>
                    <?php endif; ?>
                    <span class="pick-go" aria-hidden="true">→</span>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
