<?php
/** @var array $stats @var array $byStatus @var array $bySource @var array $byPlatform
 *  @var array $byType @var array $coverage @var array $agenda @var array $attention */

$typeTotal = array_sum(array_column($byType, 'total'));

$kindLabel = [
    'content'  => __('ui.nav.content'),
    'embargo'  => __('ui.field.embargo'),
    'deadline' => __('ui.field.deadline'),
    'expiry'   => __('ui.field.redeem_by'),
];
?>
<h1><?= e(__('ui.nav.dashboard')) ?></h1>

<section class="tiles">
    <a class="tile" href="<?= e(url('/keys?status=available')) ?>">
        <span class="tile-value"><?= (int) $stats['available'] ?></span>
        <span class="tile-label"><?= e(code_label('key_status', 'available')) ?></span>
    </a>
    <a class="tile" href="<?= e(url('/keys?status=for_giveaway')) ?>">
        <span class="tile-value"><?= (int) $stats['giveaway'] ?></span>
        <span class="tile-label"><?= e(code_label('key_status', 'for_giveaway')) ?></span>
    </a>
    <a class="tile" href="<?= e(url('/content?status=planned')) ?>">
        <span class="tile-value"><?= (int) $stats['planned'] ?></span>
        <span class="tile-label"><?= e(code_label('stream_status', 'planned')) ?></span>
    </a>
    <a class="tile" href="<?= e(url('/embargoes')) ?>">
        <span class="tile-value <?= $stats['embargoed'] > 0 ? 'warn' : '' ?>"><?= (int) $stats['embargoed'] ?></span>
        <span class="tile-label"><?= e(__('ui.label.embargoed')) ?></span>
    </a>
    <a class="tile" href="<?= e(url('/content')) ?>">
        <span class="tile-value <?= $stats['conflicts'] > 0 ? 'danger' : '' ?>"><?= (int) $stats['conflicts'] ?></span>
        <span class="tile-label"><?= e(__('ui.label.conflicts')) ?></span>
    </a>
    <a class="tile" href="<?= e(url('/catalog')) ?>">
        <span class="tile-value"><?= (int) $stats['games'] ?></span>
        <span class="tile-label"><?= e(__('ui.nav.games')) ?></span>
    </a>
    <a class="tile" href="<?= e(url('/streamers')) ?>">
        <span class="tile-value"><?= (int) $stats['streamers'] ?></span>
        <span class="tile-label"><?= e(__('ui.nav.streamers')) ?></span>
    </a>
</section>

<?php if ($typeTotal > 0): ?>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.vault_composition')) ?></h2>
        <span class="muted small"><?= e(sprintf(__('ui.label.n_keys'), $typeTotal)) ?></span>
    </div>

    <?= Chart::split(array_map(static fn (array $r): array => [
        'label' => code_label('key_type', $r['key_type']),
        'value' => (int) $r['total'],
    ], $byType)) ?>

    <ul class="legend">
        <?php foreach ($byType as $i => $r): ?>
            <li>
                <span class="swatch" style="background: <?= $i === 0 ? Chart::INK : Chart::INK_SOFT ?>"></span>
                <?= e(code_label('key_type', $r['key_type'])) ?>
                <span class="muted"><?= (int) $r['total'] ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<div class="chart-grid">
    <?php
    $panels = [
        [__('ui.label.keys_by_status'),   $byStatus,   url('/keys')],
        [__('ui.label.keys_by_source'),   $bySource,   url('/keys')],
        [__('ui.label.keys_by_platform'), $byPlatform, url('/keys')],
        [__('ui.nav.coverage'),           $coverage,   url('/content')],
    ];

    foreach ($panels as [$title, $data, $href]):
        if ($data === []) {
            continue;
        }
    ?>
        <section class="card">
            <div class="card-head">
                <h2><?= e($title) ?></h2>
                <a class="btn small ghost" href="<?= e($href) ?>"><?= e(__('ui.action.details')) ?></a>
            </div>
            <?= Chart::bars($data) ?>
        </section>
    <?php endforeach; ?>
</div>

<div class="chart-grid">
    <section class="card">
        <h2><?= e(__('ui.label.agenda')) ?></h2>
        <?php if ($agenda === []): ?>
            <p class="empty"><?= e(__('ui.message.nothing_planned')) ?></p>
        <?php else: ?>
            <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('ui.field.scheduled')) ?></th>
                    <th><?= e(__('ui.field.kind')) ?></th>
                    <th><?= e(__('ui.field.title')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($agenda as $row): ?>
                    <tr>
                        <td class="nowrap"><?= e(fmt_date($row['at'])) ?></td>
                        <td><span class="badge"><?= e($kindLabel[$row['kind']] ?? $row['kind']) ?></span></td>
                        <td><?= e(mb_strimwidth((string) $row['label'], 0, 52, '…')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
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
            <li class="<?= $attention['placeholders'] > 0 ? 'on' : '' ?>">
                <a href="<?= e(url('/keys')) ?>">
                    <strong><?= (int) $attention['placeholders'] ?></strong>
                    <?= e(__('ui.label.todo_placeholders')) ?>
                </a>
            </li>
            <li class="<?= $attention['vague_dates'] > 0 ? 'on' : '' ?>">
                <a href="<?= e(url('/catalog?stale=1')) ?>">
                    <strong><?= (int) $attention['vague_dates'] ?></strong>
                    <?= e(__('ui.label.todo_vague_dates')) ?>
                </a>
            </li>
        </ul>
    </section>
</div>
