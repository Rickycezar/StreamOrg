<?php
/** @var array $counts @var int $thisWeek @var array $backlog
 *  @var array $content @var array<int, array{path:string, updated_at:string}> $contentArt @var array $filters @var array $statuses @var array $platforms
 *  @var array $keys @var array $keySources @var array $gamePlatforms @var list<string> $tagSources
 *  @var list<array{code:string, uses_game_tag:bool}> $sponsors @var list<string> $titleOrder
 *  @var bool $canAddGames @var ?string $twitchLogin @var array $schedule @var bool $twitchCategories
 *  @var int $contentMinutes @var list<array{id:int, prefix:string, is_default:bool}> $prefixes
 *  @var array<string, array{base:int, dates:list<array{0:int, 1:int}>}> $timeline counters and the content they number
 *  @var string $collabPrefix the word before collab guests ("ft.")
 *  @var array{state:string, synced:int, last:?string} $twitchSchedule */

/** A key as the content forms list it: platform, type, status, site and its redeem-by date. */
$keyLabel = static fn (array $k): string => code_label('game_platform', $k['platform_code']) . ' · '
    . code_label('key_type', $k['key_type']) . ' · '
    . code_label('key_status', $k['status']) . ' · '
    . code_label('key_platform', $k['source_code'])
    . ($k['expires_at'] ? ' · ' . __('ui.label.redeem_by') . ' ' . fmt_date($k['expires_at']) : '');
?>
<?php
$tab = in_array($_GET['tab'] ?? '', ['calendar', 'list'], true)
    ? (string) $_GET['tab']
    : (array_filter($filters, static fn ($v): bool => $v !== '' && $v !== null && $v !== []) !== [] ? 'list' : 'calendar');
$filterActive = array_filter($filters, static fn ($v): bool => $v !== '' && $v !== []) !== [];
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.content')) ?></h1>
    <?php $helpPage = 'content'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
    <button type="button" class="btn primary" data-new data-modal-form="#add-content"
            data-modal-title="<?= e(__('ui.action.add_content')) ?>"><?= e(__('ui.action.add_content')) ?></button>
</div>

<?php
$vendor = static fn (string $file): string => url('/assets/vendor/' . $file)
    . '?v=' . (@filemtime(dirname(__DIR__, 2) . '/public/assets/vendor/' . $file) ?: 0);
$fcLocale = strtolower(Lang::locale()) === 'pt-br' ? 'pt-br' : 'en';
?>
<?= View::partial('partials/tabs', ['name' => 'content', 'active' => $tab, 'tabs' => [
    'calendar' => __('ui.label.tab_calendar'),
    'list'     => __('ui.label.tab_list'),
]]) ?>

<div data-tab-panel="calendar" <?= $tab === 'calendar' ? '' : 'hidden' ?>>
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
                    <div class="backlog-item undated-row" data-id="<?= (int) $row['id'] ?>"
                         data-event="<?= e(json_encode($item, JSON_UNESCAPED_UNICODE)) ?>"<?= $item['extendedProps']['art'] ? ' data-row-art style="--row-art: url(&quot;' . e($item['extendedProps']['art']) . '&quot;)"' : '' ?>>
                        <span class="undated-open">
                            <small class="undated-game"><?= e($row['games'] ?: __('ui.dashboard.no_games')) ?></small>
                            <strong class="undated-title"><?= e($row['title']) ?></strong>
                        </span>
                        <?php if ($row['deadline']): ?>
                            <span class="badge undated-due" title="<?= e(__('ui.field.deadline') . ' ' . fmt_date($row['deadline'])) ?>"><?= e((string) $item['extendedProps']['deadlineShort']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <p class="empty backlog-empty <?= $backlog === [] ? '' : 'hidden' ?>"><?= e(__('ui.message.planner_backlog_empty')) ?></p>
            </div>
        </aside>
    </div>
</section>
</div>

<div data-tab-panel="list" <?= $tab === 'list' ? '' : 'hidden' ?>>
<section class="tiles">
    <?php foreach ($statuses as $status): ?>
        <a class="tile <?= $filters['status'] === [$status] ? 'active' : '' ?>"
           href="<?= e(url('/content?status=' . $status)) ?>">
            <span class="tile-value"><?= (int) ($counts[$status] ?? 0) ?></span>
            <span class="tile-label"><?= e(code_label('stream_status', $status)) ?></span>
        </a>
    <?php endforeach; ?>

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

<form class="card filters" method="get" action="<?= e(url('/content')) ?>" data-filters>
    <input type="hidden" name="tab" value="list">
    <?= View::partial('partials/filter_search', ['value' => $filters['q']]) ?>
    <?= View::partial('partials/filter_select', [
        'name' => 'status', 'label' => __('ui.field.status'), 'selected' => $filters['status'],
        'options' => array_combine($statuses, array_map(static fn ($s) => code_label('stream_status', $s), $statuses)),
    ]) ?>
    <?= View::partial('partials/filter_select', [
        'name' => 'platform', 'label' => __('ui.field.platform'), 'selected' => $filters['platform'],
        'options' => array_combine($platforms, array_map(static fn ($p) => code_label('streaming_platform', $p), $platforms)),
    ]) ?>
    <?= View::partial('partials/filter_select', [
        'name' => 'dated', 'label' => __('ui.field.scheduled'), 'selected' => $filters['dated'], 'multiple' => false,
        'options' => ['dated' => __('ui.label.dated'), 'undated' => __('ui.label.undated')],
    ]) ?>
    <?= View::partial('partials/filter_actions', ['clear' => '/content?tab=list', 'active' => $filterActive]) ?>
</form>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.content')) ?> (<?= count($content) ?>)</h2>
    </div>

    <form id="add-content" class="subform hidden content-form" method="post" action="<?= e(url('/content')) ?>">
        <?= Csrf::field() ?>
        <fieldset class="form-section">
            <legend><?= e(__('ui.label.section_content')) ?></legend>
            <div class="title-block">
                <?= View::partial('content/title_field', [
                    'prefixes' => $prefixes,
                    'value'    => ContentDefaults::newTitle($prefixes)['value'],
                    'caret'    => ContentDefaults::newTitle($prefixes)['caret'],
                    'streamId' => null,
                    'order'    => $titleOrder,
                ]) ?>
            </div>
            <div class="grid">
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
                    <span><?= e(__('ui.field.status')) ?></span>
                    <select name="status">
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= e($status) ?>"><?= e(code_label('stream_status', $status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </fieldset>

        <fieldset class="form-section">
            <legend><?= e(__('ui.label.section_credits')) ?></legend>
            <div class="grid">
                <?= View::partial('content/sponsor_field', ['sponsors' => $tagSources, 'selected' => '']) ?>
                <label>
                    <span><?= e(__('ui.nav.collabs')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                    <select name="collab_id" id="content-collab" data-content-collab>
                        <option value=""><?= e(__('ui.label.no_collab')) ?></option>
                        <?php foreach ($collabs as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= e($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </fieldset>

        <fieldset class="form-section">
            <legend><?= e(__('ui.label.section_game')) ?></legend>
            <div class="grid">
                <label class="grow">
                    <span><?= e(__('ui.field.game')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                    <select name="game_id" id="content-game" data-picker="games" data-content-game
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
                    <select name="game_key_id" id="content-key" data-content-key disabled>
                        <option value=""><?= e('—') ?></option>
                    </select>
                </label>

                <label data-content-embargo-field>
                    <span><?= e(__('ui.field.embargo')) ?> <small class="muted"><?= e(__('ui.label.per_game')) ?></small></span>
                    <input type="datetime-local" name="embargo_until" id="content-embargo" data-content-embargo
                           data-default="<?= e($defaultEmbargo) ?>" disabled>
                    <small class="muted"><?= e(__('ui.label.embargo_default')) ?></small>
                </label>
            </div>

            <div class="inline-actions">
                <?php if ($canAddGames): ?>
                    <button type="button" class="btn small" data-toggle="#inline-game">+ <?= e(__('ui.action.new_game_api')) ?></button>
                <?php endif; ?>
                <button type="button" class="btn small" data-toggle="#inline-key" data-inline-key-open>+ <?= e(__('ui.action.new_key')) ?></button>
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
        </fieldset>

        <fieldset class="form-section">
            <legend><?= e(__('ui.label.section_when')) ?></legend>
            <?= View::partial('content/when_fields', [
                'start' => '', 'minutes' => $contentMinutes, 'deadline' => $defaultDeadline, 'streamId' => null, 'isNew' => true,
            ]) ?>
        </fieldset>

        <fieldset class="form-section">
            <legend><?= e(__('ui.field.notes')) ?></legend>
            <label>
                <span class="visually-hidden"><?= e(__('ui.field.notes')) ?></span>
                <textarea name="notes" rows="2"></textarea>
            </label>
        </fieldset>

        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>

    <?php if ($content === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table data-sortable data-table="content">
            <thead>
            <tr>
                <th class="col-actions"><span class="visually-hidden"><?= e(__('ui.label.actions')) ?></span></th>
                <th data-col="scheduled"><?= e(__('ui.field.scheduled')) ?></th>
                <th data-col="title"><?= e(__('ui.field.title')) ?></th>
                <th data-col="game"><?= e(__('ui.field.game')) ?></th>
                <th data-col="platform"><?= e(__('ui.field.platform')) ?></th>
                <th data-col="embargo"><?= e(__('ui.field.embargo')) ?></th>
                <th data-col="deadline"><?= e(__('ui.field.deadline')) ?></th>
                <th data-col="key"><?= e(__('ui.field.key')) ?></th>
                <th data-col="status"><?= e(__('ui.field.status')) ?></th>
                <th data-col="added"><?= e(__('ui.field.added_at')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($content as $row): ?>
                <tr data-content-id="<?= (int) $row['id'] ?>"<?= $row['game_id'] ? row_art($contentArt[(int) $row['game_id']]['path'] ?? null, $contentArt[(int) $row['game_id']]['updated_at'] ?? null) : '' ?>>
                    <td class="rowactions">
                        <?php if ($twitchLogin !== null && $row['platform_code'] === 'twitch'): ?>
                            <button type="button" class="btn small twitch-push" data-id="<?= (int) $row['id'] ?>"
                                    title="<?= e(sprintf(__('ui.action.twitch_send_hint'), $twitchLogin)) ?>">
                                <?= icon_label('twitch', __('ui.action.twitch_send')) ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn small content-detail" data-id="<?= (int) $row['id'] ?>">
                            <?= icon_label('eye', __('ui.action.details')) ?>
                        </button>
                        <button type="button" class="btn small" data-modal-form="#edit-content-<?= (int) $row['id'] ?>"
                                data-modal-title="<?= e($row['title']) ?>">
                            <?= icon_label('edit', __('ui.action.edit')) ?>
                        </button>
                    </td>
                    <td data-col="scheduled" data-sort="<?= e(sort_key($row['scheduled_start'])) ?>" data-label="<?= e(__('ui.field.scheduled')) ?>">
                        <?php if ($row['scheduled_start']): ?>
                            <?= e(fmt_datetime($row['scheduled_start'])) ?>
                        <?php else: ?>
                            <span class="badge"><?= e(__('ui.label.undated')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-card-title data-col="title" data-label="<?= e(__('ui.field.title')) ?>">
                        <?php $cover = $row['game_id'] ? ($contentArt[(int) $row['game_id']] ?? null) : null; ?>
                        <img class="card-cover" loading="lazy" alt=""
                             src="<?= e($cover ? GameImages::publicUrl($cover['path'], $cover['updated_at']) : url('/assets/game-missing.svg')) ?>">
                        <?= e($row['title']) ?>
                        <?php if ($row['collab_session_id']): ?>
                            <a class="badge together-badge" href="<?= e(url(CollabSessions::LINK . (int) $row['collab_session_id'])) ?>">👥 <?= e(__('ui.label.together_badge')) ?></a>
                        <?php elseif ($row['is_collab']): ?>
                            <span class="badge"><?= e(__('ui.label.collab')) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($row['notes'])): ?>
                            <small class="muted block"><?= e($row['notes']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td data-col="game" data-label="<?= e(__('ui.field.game')) ?>"><?= e($row['games'] ?: '—') ?></td>
                    <td data-col="platform" data-label="<?= e(__('ui.field.platform')) ?>"><?= e(code_label('streaming_platform', $row['platform_code'])) ?></td>
                    <td data-col="embargo" data-sort="<?= e(sort_key($row['embargo_until'] ?? null ?: ($row['expires_at'] ?? null))) ?>" data-label="<?= e(__('ui.field.embargo')) ?>">
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
                    <td data-col="deadline" data-sort="<?= e(sort_key($row['deadline'])) ?>" data-label="<?= e(__('ui.field.deadline')) ?>">
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
                    <td data-col="key" data-sort="<?= (int) $row['game_count'] === 0 ? '' : e((string) ((int) $row['keyed_count'] / (int) $row['game_count'])) ?>" data-label="<?= e(__('ui.field.key')) ?>">
                        <?php if ((int) $row['keyed_count'] === 0): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <span class="badge ok"><?= (int) $row['keyed_count'] ?>/<?= (int) $row['game_count'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-col="status" data-label="<?= e(__('ui.field.status')) ?>">
                        <select class="status-select content-status" data-id="<?= (int) $row['id'] ?>">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>>
                                    <?= e(code_label('stream_status', $status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="dates" data-col="added" data-label="<?= e(__('ui.field.added_at')) ?>" data-sort="<?= e(sort_key($row['created_at'])) ?>"
                        title="<?= e(fmt_datetime($row['created_at'])) ?>"><?= e(fmt_date($row['created_at'])) ?></td>
                </tr>

                <tr id="edit-content-<?= (int) $row['id'] ?>" class="editrow hidden">
                    <td colspan="9">
                        <form class="inline-edit content-form" data-endpoint="/content/update" data-id="<?= (int) $row['id'] ?>">
                                <?php if ($row['collab_session_id']): ?>
                                    <p class="notice together-note"><?= e(__('ui.message.together_edit_note')) ?>
                                        <a href="<?= e(url(CollabSessions::LINK . (int) $row['collab_session_id'])) ?>"><?= e(__('ui.action.together_open')) ?></a></p>
                                <?php endif; ?>
                            <fieldset class="form-section">
                                <legend><?= e(__('ui.label.section_content')) ?></legend>
                                <div class="title-block">
                                    <?= View::partial('content/title_field', [
                                        'prefixes' => $prefixes,
                                        'value'    => (string) ($row['title_template'] ?? $row['title']),
                                        'streamId' => (int) $row['id'],
                                        'order'    => $titleOrder,
                                    ]) ?>
                                </div>
                                <div class="grid">
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
                                    <?= View::partial('content/sponsor_field', ['sponsors' => $tagSources, 'selected' => '']) ?>
                                </div>
                            </fieldset>
                            <fieldset class="form-section">
                                <legend><?= e(__('ui.label.section_game')) ?></legend>
                                <div class="grid content-game-fields">
                                    <label class="grow">
                                        <span><?= e(__('ui.field.game')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                                        <select name="game_id" id="content-game-<?= (int) $row['id'] ?>" data-picker="games" data-content-game
                                                data-placeholder="<?= e(__('ui.label.no_game')) ?>">
                                            <option value=""><?= e(__('ui.label.no_game')) ?></option>
                                            <?php if ($row['game_id']): ?>
                                                <option value="<?= (int) $row['game_id'] ?>" selected><?= e((string) $row['game_title']) ?></option>
                                            <?php endif; ?>
                                        </select>
                                    </label>
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
                                        <span><?= e(__('ui.field.key')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                                        <select name="game_key_id" data-content-key>
                                            <option value=""><?= e('—') ?></option>
                                            <?php $listed = false; foreach ($keys as $k): if ((int) $k['game_id'] !== (int) $row['game_id']) { continue; } $listed = $listed || (int) $k['id'] === (int) $row['game_key_id']; ?>
                                                <option value="<?= (int) $k['id'] ?>" <?= (int) $k['id'] === (int) $row['game_key_id'] ? 'selected' : '' ?>><?= e($keyLabel($k)) ?></option>
                                            <?php endforeach; ?>
                                            <?php if ($row['game_key_id'] && !$listed): ?>
                                                <option value="<?= (int) $row['game_key_id'] ?>" selected><?= e(__('ui.label.current_key')) ?></option>
                                            <?php endif; ?>
                                        </select>
                                    </label>
                                    <label data-content-embargo-field>
                                        <span><?= e(__('ui.field.embargo')) ?> <small class="muted"><?= e(__('ui.label.per_game')) ?></small></span>
                                        <input type="datetime-local" name="embargo_until" data-content-embargo data-default="<?= e($defaultEmbargo) ?>"
                                               value="<?= e($row['embargo_until'] ? substr(str_replace(' ', 'T', $row['embargo_until']), 0, 16) : '') ?>">
                                    </label>
                                    <?php if ($canAddGames): ?>
                                        <div class="inline-actions">
                                            <button type="button" class="btn small" data-toggle="#inline-game-<?= (int) $row['id'] ?>">+ <?= e(__('ui.action.new_game_api')) ?></button>
                                        </div>
                                        <fieldset id="inline-game-<?= (int) $row['id'] ?>" class="inset hidden" data-game-import="#content-game-<?= (int) $row['id'] ?>">
                                            <legend><?= e(__('ui.action.new_game_api')) ?></legend>
                                            <label>
                                                <span><?= e(__('ui.action.search')) ?></span>
                                                <input type="search" class="game-import-search" autocomplete="off"
                                                       placeholder="<?= e(__(Twitch::isConfigured() ? 'ui.label.search_twitch_hint' : 'ui.label.search_hint')) ?>">
                                            </label>
                                            <div class="results game-import-results"></div>
                                        </fieldset>
                                    <?php endif; ?>
                                </div>
                            </fieldset>
                            <fieldset class="form-section">
                                <legend><?= e(__('ui.label.section_when')) ?></legend>
                                <?= View::partial('content/when_fields', [
                                    'start'    => $row['scheduled_start'] ? substr(str_replace(' ', 'T', (string) $row['scheduled_start']), 0, 16) : '',
                                    'minutes'  => (int) ($row['planned_minutes'] ?? $contentMinutes),
                                    'deadline' => $row['deadline'] ? substr(str_replace(' ', 'T', (string) $row['deadline']), 0, 16) : '',
                                    'streamId' => (int) $row['id'],
                                ]) ?>
                            </fieldset>
                            <fieldset class="form-section">
                                <legend><?= e(__('ui.label.section_more')) ?></legend>
                                <div class="grid">
                                    <label class="grow">
                                        <span><?= e(__('ui.field.vod_url')) ?></span>
                                        <input type="url" name="vod_url" value="<?= e($row['vod_url'] ?? '') ?>">
                                    </label>
                                    <label class="grow">
                                        <span><?= e(__('ui.field.notes')) ?></span>
                                        <input type="text" name="notes" value="<?= e($row['notes'] ?? '') ?>">
                                    </label>
                                </div>
                            </fieldset>
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
</div>

<?php
$pageGlobals = [
    'STREAMORG_TITLE_TIMELINE' => (object) $timeline,
    'STREAMORG_COLLAB_PREFIX'  => $collabPrefix,
    'STREAMORG_SPONSORS' => array_map(static fn (array $s): array => [
        'code'     => $s['code'],
        'label'    => code_label('key_platform', $s['code']),
        'game_tag' => (bool) $s['uses_game_tag'],
    ], $sponsors),
    'STREAMORG_KEYS' => array_map(static fn (array $k): array => [
        'id'      => (int) $k['id'],
        'game_id' => (int) $k['game_id'],
        'label'   => $keyLabel($k),
        'expires' => $k['expires_at'],
        'source'  => $k['source_code'],
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
