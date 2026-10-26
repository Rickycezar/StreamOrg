<?php
/** @var array $collabs @var array $counts @var array $cast @var array $filters
 *  @var array $statuses @var array $roles @var array $streamers @var array $platforms
 *  @var array<int, array<int, string>> $castRoles each collab's cast roles, by streamer
 *  @var list<array> $together joint plans the user is in @var array<int,int> $onStreamOrg cast members on StreamOrg, by collab
 *  @var array<int,int> $sessions active joint plan, by collab */

/** One chosen streamer in a collab form: picture, name, role, and a way to take them out. */
$castRow = static function (array $s, string $role, array $roles, bool $onStreamOrg): string {
    $id     = $s['id'] === '__ID__' ? '__ID__' : (int) $s['id'];
    $avatar = !empty($s['avatar_url']) ? '<img class="cast-avatar" src="' . e((string) $s['avatar_url']) . '" alt="" loading="lazy" referrerpolicy="no-referrer">'
                                       : '<span class="cast-avatar">' . e(mb_strtoupper(mb_substr((string) $s['name'], 0, 1))) . '</span>';
    $options = '';

    foreach ($roles as $r) {
        $options .= '<option value="' . e($r) . '"' . ($r === $role ? ' selected' : '') . '>' . e(code_label('collab_role', $r)) . '</option>';
    }

    return '<div class="cast-row" data-cast-id="' . $id . '">'
        . '<input type="hidden" name="streamers[]" value="' . $id . '">' . $avatar
        . '<span class="cast-name"><span data-cast-name>' . e((string) $s['name']) . '</span>'
        . ($onStreamOrg ? ' <span class="badge on-streamorg" title="' . e(__('ui.label.on_streamorg_hint')) . '">' . e(__('ui.label.on_streamorg')) . '</span>' : '')
        . '</span><select name="roles[' . $id . ']" class="cast-role">' . $options . '</select>'
        . '<button type="button" class="cast-remove" data-cast-remove aria-label="' . e(__('ui.action.remove')) . '" title="' . e(__('ui.action.remove')) . '">×</button></div>';
};

/** The search over the user's streamers that adds them to the cast. */
$castPicker = static fn (): string => '<div class="cast-picker" data-cast-picker>'
    . '<input type="search" data-cast-pick-search autocomplete="off" placeholder="' . e(__('ui.label.cast_pick_hint')) . '" aria-label="' . e(__('ui.label.cast_pick_hint')) . '">'
    . '<div class="cast-pick-results" data-cast-pick-results hidden></div></div>';
?>
<div class="page-head">
    <h1><?= e(__('ui.nav.collabs')) ?></h1>
    <?php $helpPage = 'collabs'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
</div>
<p class="muted"><?= e(__('ui.message.collabs_intro')) ?></p>

<section class="tiles">
    <?php foreach ($statuses as $status): ?>
        <a class="tile <?= $filters['status'] === $status ? 'active' : '' ?>"
           href="<?= e(url('/collabs?status=' . $status)) ?>">
            <span class="tile-value"><?= (int) ($counts[$status] ?? 0) ?></span>
            <span class="tile-label"><?= e(code_label('collab_status', $status)) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<?php if ($together !== []): ?>
    <section class="card together-list">
        <div class="card-head">
            <h2><?= e(__('ui.label.together_title')) ?> (<?= count($together) ?>)</h2>
            <?php $helpPage = 'together'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
        </div>
        <ul>
            <?php foreach ($together as $t):
                $invited  = $t['member_state'] === 'invited';
                $isDraft  = $t['status'] === 'draft';
                $toAnswer = !$invited && !$isDraft && $t['proposed_at'] !== null && !$t['approves']; ?>
                <li class="<?= $invited || $toAnswer || $isDraft ? 'needs-you' : '' ?>">
                    <a class="together-link" href="<?= e(url(CollabSessions::LINK . (int) $t['id'])) ?>">
                        <strong><?= e($t['title']) ?></strong>
                        <small class="muted">
                            <?= e($t['member_role'] === 'host' ? __('ui.label.together_you_host') : sprintf(__('ui.label.together_hosted_by'), $t['host_name'])) ?>
                            <?php if ($t['others']): ?> · <?= e($t['others']) ?><?php endif; ?>
                        </small>
                    </a>
                    <span class="together-time"><?= e($t['agreed_start'] ? fmt_datetime($t['agreed_start']) : __('ui.label.together_no_time')) ?></span>
                    <?php if ($isDraft): ?>
                        <span class="badge warn"><?= e(__('ui.label.together_draft')) ?></span>
                    <?php elseif ($invited): ?>
                        <span class="badge warn"><?= e(__('ui.label.together_invitation')) ?></span>
                    <?php elseif ($toAnswer): ?>
                        <span class="badge warn"><?= e(__('ui.label.together_new_time')) ?></span>
                    <?php elseif ($t['proposed_at'] !== null): ?>
                        <span class="badge"><?= e(__('ui.label.together_waiting_others')) ?></span>
                    <?php endif; ?>
                    <a class="btn small<?= $invited || $toAnswer || $isDraft ? ' primary' : '' ?>" href="<?= e(url(CollabSessions::LINK . (int) $t['id'])) ?>"><?= e(__($isDraft ? 'ui.action.together_finish_draft' : ($invited ? 'ui.action.together_see_invite' : ($toAnswer ? 'ui.action.together_answer' : 'ui.action.together_open')))) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.nav.collabs')) ?> (<?= count($collabs) ?>)</h2>
        <button type="button" class="btn primary" data-new data-modal-form="#add-collab"
                data-modal-title="<?= e(__('ui.action.create')) ?>"><?= e(__('ui.action.create')) ?></button>
    </div>

    <?php if ($streamers === []): ?>
        <p class="empty">
            <?= e(__('ui.message.no_streamers_yet')) ?>
            <a href="<?= e(url('/streamers')) ?>"><?= e(__('ui.nav.streamers')) ?></a>
        </p>
    <?php endif; ?>

    <form id="add-collab" method="post" action="<?= e(url('/collabs')) ?>" class="subform hidden">
        <?= Csrf::field() ?>
        <div class="grid">
            <label class="grow">
                <span><?= e(__('ui.field.title')) ?></span>
                <input type="text" name="title" required>
            </label>
            <label>
                <span><?= e(__('ui.field.status')) ?></span>
                <select name="status">
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>"><?= e(code_label('collab_status', $status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.platform')) ?></span>
                <select name="platform">
                    <option value=""></option>
                    <?php foreach ($platforms as $platform): ?>
                        <option value="<?= e($platform) ?>" <?= $platform === 'twitch' ? 'selected' : '' ?>>
                            <?= e(code_label('streaming_platform', $platform)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.proposed_at')) ?></span>
                <input type="datetime-local" name="proposed_at">
            </label>
        </div>

        <fieldset class="inset">
            <legend><?= e(__('ui.field.streamers')) ?></legend>
            <p class="muted small"><?= e(__('ui.message.collab_cast_hint')) ?></p>
            <?= $castPicker() ?>
            <?php require __DIR__ . '/_cast_add.php'; ?>
            <div class="cast-list" data-cast-empty="<?= e(__('ui.label.cast_none_chosen')) ?>"></div>
        </fieldset>

        <label>
            <span><?= e(__('ui.field.notes')) ?></span>
            <textarea name="notes" rows="2"></textarea>
        </label>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>

    <template data-cast-template>
        <?= $castRow(['id' => '__ID__', 'name' => '__NAME__', 'avatar_url' => null], 'guest', $roles, false) ?>
    </template>

    <?php if ($collabs === []): ?>
        <p class="empty"><?= e(__('ui.message.empty_list')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
        <table data-sortable data-table="collabs">
            <thead>
            <tr>
                <th class="col-actions"><span class="visually-hidden"><?= e(__('ui.label.actions')) ?></span></th>
                <th data-col="title"><?= e(__('ui.field.title')) ?></th>
                <th data-col="streamers"><?= e(__('ui.field.streamers')) ?></th>
                <th data-col="platform"><?= e(__('ui.field.platform')) ?></th>
                <th data-col="when"><?= e(__('ui.field.proposed_at')) ?></th>
                <th data-col="status"><?= e(__('ui.field.status')) ?></th>
                <th data-col="streams"><?= e(__('ui.nav.content')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($collabs as $row): $picked = $cast[(int) $row['id']] ?? []; ?>
                <tr>
                    <td class="rowactions">
                        <?php if (isset($sessions[(int) $row['id']])): ?>
                            <a class="btn small primary" href="<?= e(url(CollabSessions::LINK . (int) $sessions[(int) $row['id']])) ?>"><?= icon_label('users', __('ui.action.together_open')) ?></a>
                        <?php elseif (($onStreamOrg[(int) $row['id']] ?? 0) > 0): ?>
                            <form method="post" action="<?= e(url('/collabs/together')) ?>">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn small primary" title="<?= e(__('ui.action.together_plan_hint')) ?>">
                                    <?= icon_label('users', sprintf(__('ui.action.together_plan'), (int) $onStreamOrg[(int) $row['id']])) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <button type="button" class="btn small<?= isset($sessions[(int) $row['id']]) || ($onStreamOrg[(int) $row['id']] ?? 0) > 0 ? '' : ' primary' ?> collab-plan" data-id="<?= (int) $row['id'] ?>"
                                title="<?= e(__('ui.action.plan_stream_hint')) ?>">
                            <?= icon_label('calendar', __('ui.action.plan_stream')) ?>
                        </button>
                        <button type="button" class="btn small" data-modal-form="#edit-collab-<?= (int) $row['id'] ?>"
                                data-modal-title="<?= e($row['title']) ?>"><?= icon_label('edit', __('ui.action.edit')) ?></button>
                    </td>
                    <td data-card-title data-col="title" data-label="<?= e(__('ui.field.title')) ?>">
                        <?= e($row['title']) ?>
                        <?php if (!empty($row['notes'])): ?>
                            <small class="muted block"><?= e($row['notes']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td data-col="streamers" data-label="<?= e(__('ui.field.streamers')) ?>">
                        <?php if ($row['streamers']): ?>
                            <?= e($row['streamers']) ?>
                        <?php else: ?>
                            <span class="badge warn"><?= e(__('ui.label.no_streamers')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-col="platform" data-label="<?= e(__('ui.field.platform')) ?>"><?= e($row['platform_code'] ? code_label('streaming_platform', $row['platform_code']) : '—') ?></td>
                    <td data-col="when" data-sort="<?= e(sort_key($row['proposed_at'])) ?>" data-label="<?= e(__('ui.field.proposed_at')) ?>"><?= e($row['proposed_at'] ? fmt_datetime($row['proposed_at']) : '—') ?></td>
                    <td data-col="status" data-label="<?= e(__('ui.field.status')) ?>"><span class="badge"><?= e(code_label('collab_status', $row['status'])) ?></span></td>
                    <td data-col="streams" data-label="<?= e(__('ui.nav.content')) ?>">
                        <?php if ((int) $row['stream_count'] > 0): ?>
                            <a href="<?= e(url('/content')) ?>"><?= (int) $row['stream_count'] ?></a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr id="edit-collab-<?= (int) $row['id'] ?>" class="editrow hidden">
                    <td colspan="7">
                        <form class="inline-edit" data-endpoint="/collabs/update" data-id="<?= (int) $row['id'] ?>">
                            <div class="grid">
                                <label class="grow">
                                    <span><?= e(__('ui.field.title')) ?></span>
                                    <input type="text" name="title" value="<?= e($row['title']) ?>" required>
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.status')) ?></span>
                                    <select name="status">
                                        <?php foreach ($statuses as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>>
                                                <?= e(code_label('collab_status', $status)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.platform')) ?></span>
                                    <select name="platform">
                                        <option value=""></option>
                                        <?php foreach ($platforms as $platform): ?>
                                            <option value="<?= e($platform) ?>" <?= $row['platform_code'] === $platform ? 'selected' : '' ?>>
                                                <?= e(code_label('streaming_platform', $platform)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?= e(__('ui.field.proposed_at')) ?></span>
                                    <input type="datetime-local" name="proposed_at"
                                           value="<?= e($row['proposed_at'] ? substr(str_replace(' ', 'T', $row['proposed_at']), 0, 16) : '') ?>">
                                </label>
                            </div>

                            <fieldset class="inset">
                                <legend><?= e(__('ui.field.streamers')) ?></legend>
                                <p class="muted small"><?= e(__('ui.message.collab_cast_hint')) ?></p>
                                <?= $castPicker() ?>
                                <?php require __DIR__ . '/_cast_add.php'; ?>
                                <input type="hidden" name="streamers[]" value="">
                                <div class="cast-list" data-cast-empty="<?= e(__('ui.label.cast_none_chosen')) ?>">
                                    <?php foreach ($streamers as $s): if (!in_array((int) $s['id'], $picked, true)) { continue; } ?>
                                        <?= $castRow($s, (string) ($castRoles[(int) $row['id']][(int) $s['id']] ?? 'guest'), $roles, isset($linked[(int) $s['id']])) ?>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <label>
                                <span><?= e(__('ui.field.notes')) ?></span>
                                <input type="text" name="notes" value="<?= e($row['notes'] ?? '') ?>">
                            </label>
                            <div class="editrow-actions">
                                <button type="submit" class="btn primary small"><?= e(__('ui.action.save')) ?></button>
                                <button type="button" class="btn small row-cancel"><?= e(__('ui.action.cancel')) ?></button>
                                <span class="edit-result muted small"></span>
                                <button type="button" class="btn small danger-btn row-delete"
                                        data-endpoint="/collabs/delete" data-id="<?= (int) $row['id'] ?>"
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

<script type="application/json" data-globals><?= json_encode(['STREAMORG_CAST_STREAMERS' => array_map(static fn (array $s): array => [
    'id'        => (int) $s['id'],
    'name'      => (string) $s['name'],
    'avatar'    => $s['avatar_url'] ?: null,
    'streamorg' => isset($linked[(int) $s['id']]),
], $streamers)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
