<?php
/**
 * A bug report as both sides see it: where it stands (a progress line from
 * reported to fixed), what was reported (page, what happened, steps, what
 * was expected, screenshots with their pins), and its conversation and
 * history. Administrators also see internal notes and what the browser
 * sent.
 *
 * @var array $bug @var list<array> $timeline @var array<int, list<array>> $images @var bool $admin
 */
$flow    = ['new', 'confirmed', 'in_progress', 'fixed'];
$at      = array_search($bug['status'] === 'closed' ? 'fixed' : ($bug['status'] === 'need_info' ? 'confirmed' : $bug['status']), $flow, true);
$offFlow = in_array($bug['status'], ['wont_fix', 'duplicate'], true);

$shots = static function (array $list): void {
    if ($list === []) {
        return;
    }
    echo '<div class="bug-shots">';
    foreach ($list as $image) {
        $src = url('/support/image?id=' . $image['id']);
        echo '<figure class="bug-shot"><a href="' . e($src) . '" target="_blank" rel="noopener" class="bug-shot-frame">'
            . '<img src="' . e($src) . '" alt="" loading="lazy" width="' . (int) $image['width'] . '" height="' . (int) $image['height'] . '">';
        foreach ($image['pins'] as $n => $pin) {
            echo '<span class="shot-pin" style="left: ' . (float) $pin['x'] * 100 . '%; top: ' . (float) $pin['y'] * 100 . '%">' . ($n + 1) . '</span>';
        }
        echo '</a>';
        $notes = array_filter($image['pins'], static fn (array $p): bool => $p['note'] !== '');
        if ($notes !== []) {
            echo '<figcaption><ol>';
            foreach ($image['pins'] as $n => $pin) {
                echo '<li value="' . ($n + 1) . '">' . e($pin['note'] !== '' ? $pin['note'] : '—') . '</li>';
            }
            echo '</ol></figcaption>';
        }
        echo '</figure>';
    }
    echo '</div>';
};
?>
<?php if (!$offFlow): ?>
    <ol class="bug-progress" aria-label="<?= e(__('ui.support.progress')) ?>">
        <?php foreach ($flow as $i => $step): ?>
            <li class="<?= $at !== false && $i < $at ? 'done' : ($i === $at ? 'current' : '') ?>">
                <span class="bug-progress-dot"><?= $at !== false && $i < $at ? '✓' : $i + 1 ?></span>
                <span><?= e(__('ui.support.progress_' . $step)) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<section class="card bug-report">
    <dl class="bug-facts">
        <dt><?= e(__('ui.support.f_status')) ?></dt>
        <dd><span class="badge bug-<?= e($bug['status']) ?>"><?= e(__('ui.bug_status.' . $bug['status'])) ?></span>
            <?php if ($bug['status'] === 'duplicate' && $bug['duplicate_of']): ?>
                <small class="muted"><?= e(sprintf(__('ui.support.duplicate_of'), '#' . (int) $bug['duplicate_of'])) ?></small>
            <?php endif; ?></dd>
        <dt><?= e(__('ui.support.f_impact')) ?></dt>
        <dd><span class="badge impact-<?= e($bug['impact']) ?>"><?= e(__('ui.bug_impact.' . $bug['impact'])) ?></span></dd>
        <?php if ($bug['page']): ?>
            <dt><?= e(__('ui.support.f_page')) ?></dt>
            <dd><code><?= e($bug['page']) ?></code></dd>
        <?php endif; ?>
        <dt><?= e(__('ui.support.f_reported')) ?></dt>
        <dd><?= e(fmt_datetime($bug['created_at'])) ?><?= $admin && $bug['reporter'] ? ' · ' . e($bug['reporter']) : '' ?></dd>
    </dl>

    <h3 class="bug-section"><?= e(__('ui.support.q_what')) ?></h3>
    <p class="bug-text"><?= nl2br(e($bug['description'])) ?></p>

    <?php if ($bug['steps_list'] !== []): ?>
        <h3 class="bug-section"><?= e(__('ui.support.q_steps')) ?></h3>
        <ol class="bug-steps-list">
            <?php foreach ($bug['steps_list'] as $step): ?><li><?= e($step) ?></li><?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($bug['expected']): ?>
        <h3 class="bug-section"><?= e(__('ui.support.q_expected')) ?></h3>
        <p class="bug-text"><?= nl2br(e($bug['expected'])) ?></p>
    <?php endif; ?>

    <?php if (!empty($images[0])): ?>
        <h3 class="bug-section"><?= e(__('ui.support.q_shots')) ?></h3>
        <?php $shots($images[0]); ?>
    <?php endif; ?>

    <?php if ($bug['environment'] !== []): ?>
        <details class="bug-env">
            <summary><?= e(__('ui.support.env_title')) ?></summary>
            <dl>
                <?php foreach ($bug['environment'] as $key => $value): ?>
                    <dt><?= e(__('ui.support.env_' . $key)) ?></dt>
                    <dd><?php if ($key === 'error_ref' && $admin): ?><a href="<?= e(url('/admin/errors?id=' . (int) $value)) ?>">#<?= (int) $value ?></a><?php else: ?><?= e((string) $value) ?><?php endif; ?></dd>
                <?php endforeach; ?>
            </dl>
        </details>
    <?php endif; ?>
</section>

<section class="bug-timeline" aria-label="<?= e(__('ui.support.conversation')) ?>">
    <?php foreach ($timeline as $c):
        $mine = (int) $c['author_id'] === (int) $bug['user_id'];
        $fromTeam = $c['author_role'] === 'admin' && !$mine; ?>
        <?php if ($c['to_status'] !== null): ?>
            <div class="bug-event">
                <span class="badge bug-<?= e($c['to_status']) ?>"><?= e(__('ui.bug_status.' . $c['to_status'])) ?></span>
                <small class="muted"><?= e(sprintf(__('ui.support.status_by'), $fromTeam ? ($admin ? (string) $c['author'] : __('ui.support.team')) : (string) $c['author'], fmt_datetime($c['created_at'], 'd/m H:i'))) ?></small>
            </div>
        <?php endif; ?>
        <?php if ($c['body'] !== null || !empty($images[(int) $c['id']])): ?>
            <article class="bug-comment<?= $fromTeam ? ' from-team' : '' ?><?= $c['is_internal'] ? ' internal' : '' ?>">
                <header>
                    <?php $avatarUser = $c; $avatarSize = 'sm'; require dirname(__DIR__) . '/partials/avatar.php'; ?>
                    <strong><?= e((string) $c['author']) ?></strong>
                    <?php if ($fromTeam): ?><span class="badge"><?= e(__('ui.support.team')) ?></span><?php endif; ?>
                    <?php if ($c['is_internal']): ?><span class="badge warn"><?= e(__('ui.support.internal_note')) ?></span><?php endif; ?>
                    <time class="muted" datetime="<?= e($c['created_at']) ?>"><?= e(fmt_datetime($c['created_at'], 'd/m H:i')) ?></time>
                </header>
                <?php if ($c['body'] !== null): ?><p><?= nl2br(e($c['body'])) ?></p><?php endif; ?>
                <?php $shots($images[(int) $c['id']] ?? []); ?>
            </article>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
