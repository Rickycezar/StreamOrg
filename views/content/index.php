<?php
/** @var array $counts @var int $missingKeys @var int $thisWeek @var array $backlog
 *  @var array $content @var array $filters @var array $statuses @var array $platforms
 *  @var array $keys @var array $keySources @var array $gamePlatforms
 *  @var bool $canAddGames @var ?string $twitchLogin @var array $schedule @var bool $twitchCategories
 *  @var int $contentMinutes @var list<array{prefix:string, is_default:bool}> $prefixes
 *  @var array{state:string, synced:int, last:?string} $twitchSchedule */
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.content')) ?></h1>
    <?php $helpPage = 'content'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
</div>

<section class="tiles">
    <?php foreach ($statuses as $status): ?>
        <a class="tile <?= $filters['status'] === $status ? 'active' : '' ?>"
           href="<?= e(url('/content?status=' . $status)) ?>">
            <span class="tile-value"><?= (int) ($counts[$status] ?? 0) ?></span>
            <span class="tile-label"><?= e(code_label('stream_status', $status)) ?></span>
        </a>
    <?php endforeach; ?>

    <a class="tile <?= $filters['keyed'] === 'without' ? 'active' : '' ?>"
       href="<?= e(url('/content?keyed=without')) ?>"
       title="<?= e(__('ui.label.missing_key_hint')) ?>">
        <span class="tile-value <?= $missingKeys > 0 ? 'warn' : '' ?>"><?= $missingKeys ?></span>
        <span class="tile-label"><?= e(__('ui.label.missing_key')) ?></span>
    </a>

    <span class="tile" title="<?= e(__('ui.label.embargoed_hint')) ?>">
        <span class="tile-value"><?= (int) $embargoed ?></span>
        <span class="tile-label"><?= e(__('ui.label.embargoed')) ?></span>
    </span>

    <a class="tile <?= $filters['dated'] === 'undated' ? 'active' : '' ?>"
       href="<?= e(url('/content?dated=undated')) ?>" title="<?= e(__('ui.label.undated_hint')) ?>">
        <span class="tile-value"><?= (int) $undated ?></span>
        <span class="tile-label"><?= e(__('ui.label.undated')) ?></span>
    </a>

    <span class="tile" title="<?= e(__('ui.label.conflicts_hint')) ?>">
        <span class="tile-value <?= $warningTotal > 0 ? 'danger' : '' ?>"><?= (int) $warningTotal ?></span>
        <span class="tile-label"><?= e(__('ui.label.conflicts')) ?></span>
    </span>

    <span class="tile total">
        <span class="tile-value"><?= $thisWeek ?></span>
        <span class="tile-label"><?= e(__('ui.label.this_week')) ?></span>
    </span>
</section>

<?php
$vendor = static fn (string $file): string => url('/assets/vendor/' . $file)
    . '?v=' . (@filemtime(dirname(__DIR__, 2) . '/public/assets/vendor/' . $file) ?: 0);
$fcLocale = strtolower(Lang::locale()) === 'pt-br' ? 'pt-br' : 'en';
?>
<section class="card planner"
         data-planner
         data-fc-src="<?= e($vendor('fullcalendar.js')) ?>"
         <?php if ($fcLocale !== 'en'): ?>data-fc-locale-src="<?= e($vendor('fullcalendar-' . $fcLocale . '.js')) ?>"<?php endif; ?>
         data-locale="<?= e($fcLocale) ?>"
         data-now="<?= e((new DateTimeImmutable())->format('Y-m-d\TH:i:s')) ?>"
         data-events-url="<?= e(url('/content/calendar')) ?>"
         data-schedule="<?= e(json_encode((object) $schedule)) ?>"
         data-default-start="<?= e(StreamSchedule::FALLBACK_START) ?>"
         data-content-minutes="<?= $contentMinutes ?>">
    <?php if ($twitchLogin !== null): ?>
        <div class="planner-bar">
            <span class="muted small" data-twitch-schedule-status><?= e(TwitchSchedule::describe($twitchSchedule)) ?></span>
            <?php if ($twitchSchedule['state'] === 'reconnect'): ?>
                <a class="btn small" href="<?= e(url('/profile/twitch/connect')) ?>" data-turbo="false"><?= e(__('ui.action.twitch_reconnect')) ?></a>
            <?php else: ?>
                <button type="button" class="btn small twitch-schedule-send"><?= e(__('ui.action.twitch_schedule_send')) ?></button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="planner-layout">
        <div class="planner-calendar" id="planner-calendar">
            <div class="skeleton-calendar" aria-hidden="true">
                <div class="skeleton-calendar-head">
                    <span class="skeleton skeleton-line" style="width: 90px"></span>
                    <span class="skeleton skeleton-line" style="width: 160px"></span>
                    <span class="skeleton skeleton-line" style="width: 120px"></span>
                </div>
                <div class="skeleton-calendar-grid">
                    <?php for ($i = 0; $i < 42; $i++): ?><span class="skeleton"></span><?php endfor; ?>
                </div>
            </div>
        </div>

        <aside class="planner-backlog" id="planner-backlog">
            <h3><?= e(__('ui.label.undated')) ?> <span class="muted" data-backlog-count>(<?= count($backlog) ?>)</span></h3>
            <p class="muted small"><?= e(__('ui.message.planner_drag_hint')) ?></p>

            <div class="backlog-list">
                <?php foreach ($backlog as $row): ?>
                    <?php $item = ContentController::plannerItem($row); ?>
                    <div class="backlog-item" data-id="<?= (int) $row['id'] ?>"
                         data-event="<?= e(json_encode($item, JSON_UNESCAPED_UNICODE)) ?>">
                        <?php if ($item['extendedProps']['thumb']): ?>
                            <img class="picker-cover" src="<?= e($item['extendedProps']['thumb']) ?>" alt="">
                        <?php else: ?>
                            <span class="picker-cover"></span>
                        <?php endif; ?>
                        <span class="backlog-text">
                            <span class="backlog-title"><?= e($row['title']) ?></span>
                            <small class="muted">
                                <?= e($row['games'] ?: '—') ?>
                                <?php if ($row['deadline']): ?> · <?= e(__('ui.field.deadline')) ?> <?= e(fmt_date($row['deadline'])) ?><?php endif; ?>
                            </small>
                        </span>
                    </div>
                <?php endforeach; ?>
                <p class="empty backlog-empty <?= $backlog === [] ? '' : 'hidden' ?>"><?= e(__('ui.message.planner_backlog_empty')) ?></p>
            </div>
        </aside>
    </div>
</section>

<form class="card filters" method="get" action="<?= e(url('/content')) ?>">
    <label>
        <span><?= e(__('ui.action.search')) ?></span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>">
    </label>
    <label>
        <span><?= e(__('ui.field.status')) ?></span>
        <select name="status">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                    <?= e(code_label('stream_status', $status)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span><?= e(__('ui.field.platform')) ?></span>
        <select name="platform">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <?php foreach ($platforms as $platform): ?>
                <option value="<?= e($platform) ?>" <?= $filters['platform'] === $platform ? 'selected' : '' ?>>
                    <?= e(code_label('streaming_platform', $platform)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span><?= e(__('ui.field.key')) ?></span>
        <select name="keyed">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <option value="with"    <?= $filters['keyed'] === 'with' ? 'selected' : '' ?>><?= e(__('ui.label.with_key')) ?></option>
            <option value="without" <?= $filters['keyed'] === 'without' ? 'selected' : '' ?>><?= e(__('ui.label.without_key')) ?></option>
        </select>
    </label>
    <label>
        <span><?= e(__('ui.field.scheduled')) ?></span>
        <select name="dated">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <option value="dated"   <?= $filters['dated'] === 'dated' ? 'selected' : '' ?>><?= e(__('ui.label.dated')) ?></option>
            <option value="undated" <?= $filters['dated'] === 'undated' ? 'selected' : '' ?>><?= e(__('ui.label.undated')) ?></option>
        </select>
    </label>

    <div class="filter-actions">
        <button type="submit" class="btn"><?= e(__('ui.action.filter')) ?></button>
        <a class="btn ghost" href="<?= e(url('/content')) ?>"><?= e(__('ui.action.clear')) ?></a>
    </div>
</form>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.content')) ?> (<?= count($content) ?>)</h2>
        <button type="button" class="btn primary" data-new data-modal-form="#add-content"
                data-modal-title="<?= e(__('ui.action.add_content')) ?>"><?= e(__('ui.action.add_content')) ?></button>
    </div>

    <form id="add-content" class="subform hidden" method="post" action="<?= e(url('/content')) ?>">
        <?= Csrf::field() ?>
        <div class="grid">
            <?php if ($prefixes !== []): ?>
                <label>
                    <span><?= e(__('ui.field.title_prefix')) ?></span>
                    <select id="content-prefix">
                        <option value=""><?= e(__('ui.label.none')) ?></option>
                        <?php foreach ($prefixes as $p): ?>
                            <option value="<?= e($p['prefix']) ?>" <?= $p['is_default'] ? 'selected' : '' ?>><?= e($p['prefix']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label class="grow">
                <span><?= e(__('ui.field.title')) ?></span>
                <textarea name="title" rows="2" required class="title-area"></textarea>
            </label>
            <label>
                <span><?= e(__('ui.field.platform')) ?></span>
                <select name="platform" required>
                    <?php foreach ($platforms as $platform): ?>
                        <option value="<?= e($platform) ?>" <?= $platform === 'twitch' ? 'selected' : '' ?>>
                            <?= e(code_label('streaming_platform', $platform)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.scheduled')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                <input type="datetime-local" name="scheduled_start">
                <small class="muted"><?= e(__('ui.label.undated_ok')) ?></small>
            </label>
            <label>
                <span><?= e(__('ui.field.deadline')) ?></span>
                <input type="datetime-local" name="deadline" value="<?= e($defaultDeadline) ?>">
                <small class="muted"><?= e(__('ui.label.deadline_default')) ?></small>
            </label>
            <label>
                <span><?= e(__('ui.field.status')) ?></span>
                <select name="status">
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>"><?= e(code_label('stream_status', $status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="grid">
            <label>
                <span><?= e(__('ui.nav.collabs')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                <select name="collab_id" id="content-collab">
                    <option value=""><?= e(__('ui.label.no_collab')) ?></option>
                    <?php foreach ($collabs as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span><?= e(__('ui.field.sponsor')) ?> <small class="muted"><?= e(__('ui.label.tag_only')) ?></small></span>
                <select id="content-sponsor">
                    <option value=""><?= e(__('ui.label.none')) ?></option>
                    <?php foreach ($tagSources as $src): ?>
                        <option value="<?= e(code_label('key_platform', $src)) ?>">
                            <?= e(code_label('key_platform', $src)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="grid">
            <label class="grow">
                <span><?= e(__('ui.field.game')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                <select name="game_id" id="content-game" data-picker="games"
                        data-placeholder="<?= e(__('ui.label.no_game')) ?>">
                    <option value=""><?= e(__('ui.label.no_game')) ?></option>
                </select>
            </label>

            <?php if ($twitchCategories): ?>
                <label class="grow">
                    <span><?= e(__('ui.field.twitch_category')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                    <select name="category_id" data-picker="categories" data-placeholder="<?= e(__('ui.label.category_from_game')) ?>">
                        <option value=""><?= e(__('ui.label.category_from_game')) ?></option>
                    </select>
                </label>
            <?php endif; ?>

            <label class="grow">
                <span><?= e(__('ui.field.key')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                <select name="game_key_id" id="content-key" disabled>
                    <option value=""><?= e(__('ui.label.no_key_needed')) ?></option>
                </select>
            </label>

            <label>
                <span><?= e(__('ui.field.embargo')) ?> <small class="muted"><?= e(__('ui.label.per_game')) ?></small></span>
                <input type="datetime-local" name="embargo_until" id="content-embargo"
                       data-default="<?= e($defaultEmbargo) ?>" disabled>
                <small class="muted"><?= e(__('ui.label.embargo_default')) ?></small>
            </label>
        </div>

        <div class="inline-actions">
            <?php if ($canAddGames): ?>
                <button type="button" class="btn small" data-toggle="#inline-game">+ <?= e(__('ui.action.new_game_api')) ?></button>
            <?php endif; ?>
            <button type="button" class="btn small" data-toggle="#inline-key">+ <?= e(__('ui.action.new_key')) ?></button>
        </div>

        <?php if ($canAddGames): ?>
        <fieldset id="inline-game" class="inset hidden" data-game-import="#content-game">
            <legend><?= e(__('ui.action.new_game_api')) ?></legend>
            <label>
                <span><?= e(__('ui.action.search')) ?></span>
                <input type="search" class="game-import-search" autocomplete="off"
                       placeholder="<?= e(__(Twitch::isConfigured() ? 'ui.label.search_twitch_hint' : 'ui.label.search_hint')) ?>">
            </label>
            <div class="results game-import-results"></div>
        </fieldset>
        <?php endif; ?>

        <fieldset id="inline-key" class="inset hidden">
            <legend><?= e(__('ui.action.new_key')) ?></legend>
            <p class="muted small"><?= e(__('ui.message.inline_key_hint')) ?></p>
            <div class="grid">
                <label>
                    <span><?= e(__('ui.field.platform')) ?></span>
                    <select id="inline-key-platform">
                        <?php foreach ($gamePlatforms as $platform): ?>
                            <option value="<?= e($platform) ?>" <?= $platform === 'pc_steam' ? 'selected' : '' ?>>
                                <?= e(code_label('game_platform', $platform)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?= e(__('ui.field.source')) ?></span>
                    <select id="inline-key-source">
                        <?php foreach ($keySources as $source): ?>
                            <option value="<?= e($source) ?>"><?= e(code_label('key_platform', $source)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?= e(__('ui.field.key_type')) ?></span>
                    <select id="inline-key-type">
                        <?php foreach (['common', 'review'] as $type): ?>
                            <option value="<?= e($type) ?>"><?= e(code_label('key_type', $type)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?= e(__('ui.field.content_type')) ?></span>
                    <select id="inline-key-content">
                        <?php foreach (['game', 'dlc'] as $type): ?>
                            <option value="<?= e($type) ?>"><?= e(code_label('content_type', $type)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?= e(__('ui.field.status')) ?></span>
                    <select id="inline-key-status">
                        <?php foreach ($keyStatuses as $st): ?>
                            <option value="<?= e($st) ?>"><?= e(code_label('key_status', $st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?= e(__('ui.field.redeem_by')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                    <input type="datetime-local" id="inline-key-expires">
                </label>
                <label class="grow">
                    <span><?= e(__('ui.field.key_code')) ?></span>
                    <span class="keyfield">
                        <input type="text" id="inline-key-code" class="key-field masked"
                               autocomplete="off" spellcheck="false">
                    </span>
                </label>
            </div>
            <button type="button" class="btn" id="inline-key-save"><?= e(__('ui.action.save_key')) ?></button>
            <span id="inline-key-result" class="muted small"></span>
        </fieldset>

        <label>
            <span><?= e(__('ui.field.notes')) ?></span>
            <textarea name="notes" rows="2"></textarea>
        </label>

        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>

    <?php if ($content === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table data-table="content">
            <thead>
            <tr>
                <th data-col="scheduled"><?= e(__('ui.field.scheduled')) ?></th>
                <th data-col="title"><?= e(__('ui.field.title')) ?></th>
                <th data-col="game"><?= e(__('ui.field.game')) ?></th>
                <th data-col="platform"><?= e(__('ui.field.platform')) ?></th>
                <th data-col="embargo"><?= e(__('ui.field.embargo')) ?></th>
                <th data-col="deadline"><?= e(__('ui.field.deadline')) ?></th>
                <th data-col="key"><?= e(__('ui.field.key')) ?></th>
                <th data-col="status"><?= e(__('ui.field.status')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($content as $row): ?>
                <tr data-content-id="<?= (int) $row['id'] ?>">
                    <td data-col="scheduled">
                        <?php if ($row['scheduled_start']): ?>
                            <?= e(fmt_datetime($row['scheduled_start'])) ?>
                        <?php else: ?>
                            <span class="badge"><?= e(__('ui.label.undated')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-col="title">
                        <?= e($row['title']) ?>
                        <?php if ($row['is_collab']): ?>
                            <span class="badge"><?= e(__('ui.label.collab')) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($row['notes'])): ?>
                            <small class="muted block"><?= e($row['notes']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td data-col="game"><?= e($row['games'] ?: '—') ?></td>
                    <td data-col="platform"><?= e(code_label('streaming_platform', $row['platform_code'])) ?></td>
                    <td data-col="embargo">
                        <?php if (!empty($row['embargo_until'])): ?>
                            <?= e(fmt_date($row['embargo_until'])) ?>
                            <?php if ($row['breaks_embargo']): ?>
                                <span class="badge danger" title="<?= e(__('ui.message.breaks_embargo')) ?>">!</span>
                            <?php endif; ?>
                        <?php elseif (!empty($row['expires_at'])): ?>
                            <small class="muted"><?= e(__('ui.label.redeem_by')) ?> <?= e(fmt_date($row['expires_at'])) ?></small>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-col="deadline">
                        <?php if ($row['deadline']): ?>
                            <?= e(fmt_date($row['deadline'])) ?>
                            <?php if (!empty($row['misses_deadline'])): ?>
                                <span class="badge danger" title="<?= e(__('ui.message.misses_deadline')) ?>"><?= e(__('ui.label.late')) ?></span>
                            <?php elseif (!empty($row['overdue'])): ?>
                                <span class="badge danger" title="<?= e(__('ui.message.overdue')) ?>"><?= e(__('ui.label.overdue')) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($row['impossible_window'])): ?>
                                <span class="badge danger" title="<?= e(__('ui.message.impossible_window')) ?>"><?= e(__('ui.label.impossible')) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-col="key">
                        <?php if ((int) $row['game_count'] === 0): ?>
                            <span class="muted">—</span>
                        <?php elseif ((int) $row['keyed_count'] === 0): ?>
                            <span class="badge warn"><?= e(__('ui.label.no_key')) ?></span>
                        <?php else: ?>
                            <span class="badge ok"><?= (int) $row['keyed_count'] ?>/<?= (int) $row['game_count'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-col="status">
                        <select class="status-select content-status" data-id="<?= (int) $row['id'] ?>">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>>
                                    <?= e(code_label('stream_status', $status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="rowactions">
                        <?php if ($twitchLogin !== null && $row['platform_code'] === 'twitch'): ?>
                            <button type="button" class="btn small twitch-push" data-id="<?= (int) $row['id'] ?>"
                                    title="<?= e(sprintf(__('ui.action.twitch_send_hint'), $twitchLogin)) ?>">
                                <?= e(__('ui.action.twitch_send')) ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn small content-detail" data-id="<?= (int) $row['id'] ?>">
                            <?= e(__('ui.action.details')) ?>
                        </button>
                        <button type="button" class="btn small" data-modal-form="#edit-content-<?= (int) $row['id'] ?>"
                                data-modal-title="<?= e($row['title']) ?>">
                            <?= e(__('ui.action.edit')) ?>
                        </button>
                    </td>
                </tr>

                <tr id="edit-content-<?= (int) $row['id'] ?>" class="editrow hidden">
                    <td colspan="8">
                        <form class="inline-edit" data-endpoint="/content/update" data-id="<?= (int) $row['id'] ?>">
                            <div class="grid">
                                <label class="grow">
                                    <span><?= e(__('ui.field.title')) ?></span>
                                    <textarea name="title" rows="2" required class="title-area"><?= e($row['title']) ?></textarea>
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.platform')) ?></span>
                                    <select name="platform">
                                        <?php foreach ($platforms as $platform): ?>
                                            <option value="<?= e($platform) ?>" <?= $row['platform_code'] === $platform ? 'selected' : '' ?>>
                                                <?= e(code_label('streaming_platform', $platform)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.status')) ?></span>
                                    <select name="status">
                                        <?php foreach ($statuses as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>>
                                                <?= e(code_label('stream_status', $status)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.scheduled')) ?></span>
                                    <input type="datetime-local" name="scheduled_start"
                                           value="<?= e($row['scheduled_start'] ? substr(str_replace(' ', 'T', $row['scheduled_start']), 0, 16) : '') ?>">
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.deadline')) ?></span>
                                    <input type="datetime-local" name="deadline"
                                           value="<?= e($row['deadline'] ? substr(str_replace(' ', 'T', $row['deadline']), 0, 16) : '') ?>">
                                </label>
                                <?php if ((int) $row['game_count'] > 0): ?>
                                    <label>
                                        <span><?= e(__('ui.field.embargo')) ?> <small class="muted"><?= e(__('ui.label.per_game')) ?></small></span>
                                        <input type="datetime-local" name="embargo_until"
                                               value="<?= e($row['embargo_until'] ? substr(str_replace(' ', 'T', $row['embargo_until']), 0, 16) : '') ?>">
                                    </label>
                                <?php endif; ?>
                                <?php if ($twitchCategories): ?>
                                    <label class="grow">
                                        <span><?= e(__('ui.field.twitch_category')) ?></span>
                                        <select name="category_id" data-picker="categories" data-placeholder="<?= e(__('ui.label.category_from_game')) ?>">
                                            <option value=""><?= e(__('ui.label.category_from_game')) ?></option>
                                            <?php if ($row['category_id']): ?>
                                                <option value="<?= e($row['category_id']) ?>" selected><?= e((string) $row['category_name']) ?></option>
                                            <?php endif; ?>
                                        </select>
                                    </label>
                                <?php endif; ?>
                                <label class="grow">
                                    <span><?= e(__('ui.field.vod_url')) ?></span>
                                    <input type="url" name="vod_url" value="<?= e($row['vod_url'] ?? '') ?>">
                                </label>
                            </div>
                            <label>
                                <span><?= e(__('ui.field.notes')) ?></span>
                                <input type="text" name="notes" value="<?= e($row['notes'] ?? '') ?>">
                            </label>
                            <div class="editrow-actions">
                                <button type="submit" class="btn primary small"><?= e(__('ui.action.save')) ?></button>
                                <button type="button" class="btn small row-cancel"><?= e(__('ui.action.cancel')) ?></button>
                                <span class="edit-result muted small"></span>
                                <button type="button" class="btn small danger-btn row-delete"
                                        data-endpoint="/content/delete" data-id="<?= (int) $row['id'] ?>"
                                        data-label="<?= e($row['title']) ?>"><?= e(__('ui.action.delete')) ?></button>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<?php
$pageGlobals = [
    'STREAMORG_KEYS' => array_map(static fn (array $k): array => [
        'id'      => (int) $k['id'],
        'game_id' => (int) $k['game_id'],
        'label'   => code_label('game_platform', $k['platform_code']) . ' · '
                   . code_label('key_type', $k['key_type']) . ' · '
                   . code_label('key_status', $k['status']) . ' · '
                   . code_label('key_platform', $k['source_code'])
                   . ($k['expires_at'] ? ' · ' . __('ui.label.redeem_by') . ' ' . fmt_date($k['expires_at']) : ''),
        'expires' => $k['expires_at'],
    ], $keys),
    'STREAMORG_GAMES' => new stdClass(),
    'STREAMORG_COLLABS' => (object) array_reduce($collabs, static function (array $carry, array $c): array {
        $carry[(string) $c['id']] = [
            'cast'     => $c['cast_names'] ? explode('|', (string) $c['cast_names']) : [],
            'platform' => $c['platform_code'],
            'at'       => $c['proposed_at'] ? substr(str_replace(' ', 'T', (string) $c['proposed_at']), 0, 16) : null,
            'title'    => $c['title'],
        ];
        return $carry;
    }, []),
    'STREAMORG_PRESELECT_COLLAB' => $preselect,
    'STREAMORG_EMBARGOES' => (object) array_map(
        static fn (?string $d): string => $d === null ? '' : substr(str_replace(' ', 'T', $d), 0, 16),
        $embargoByGame
    ),
];
?>
<script type="application/json" data-globals><?= json_encode($pageGlobals, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
