<?php
/**
 * Administration → Errors: problems the application ran into, grouped by
 * kind, newest first. Open counts by level on top; each problem shows how
 * often it happened and when, and opens to its last request and stack
 * trace. Problems can be resolved (one, or every one ticked) and opened
 * again; resolved ones can be cleared.
 *
 * @var list<array> $errors @var array<string,int> $counts @var string $show @var string $level @var string $query @var ?int $focus
 */
$keep = static fn (array $change): string => url('/admin/errors?' . http_build_query(array_filter(array_merge(['show' => $show, 'level' => $level, 'q' => $query], $change))));
$open = array_filter($errors, static fn (array $e): bool => $e['resolved_at'] === null);
?>
<h1><?= e(__('ui.nav.errors')) ?></h1>
<p class="muted"><?= e(__('ui.message.errors_intro')) ?></p>

<section class="tiles compact error-tiles">
    <?php foreach (ErrorLog::LEVELS as $l): ?>
        <a class="tile level-<?= e($l) ?><?= $level === $l ? ' active' : '' ?>" href="<?= e($keep(['level' => $level === $l ? '' : $l, 'show' => 'open'])) ?>">
            <span class="tile-value"><?= (int) $counts[$l] ?></span>
            <span class="tile-label"><?= e(code_label('error_level', $l)) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<form class="card filters" method="get" action="<?= e(url('/admin/errors')) ?>">
    <label>
        <span><?= e(__('ui.action.search')) ?></span>
        <input type="search" name="q" value="<?= e($query) ?>" placeholder="<?= e(__('ui.label.errors_search_hint')) ?>">
    </label>
    <label>
        <span><?= e(__('ui.field.status')) ?></span>
        <select name="show">
            <?php foreach (ErrorLogController::SHOWS as $s): ?>
                <option value="<?= e($s) ?>" <?= $show === $s ? 'selected' : '' ?>><?= e(__('ui.label.errors_show_' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span><?= e(__('ui.field.error_level')) ?></span>
        <select name="level">
            <option value=""><?= e(__('ui.label.any')) ?></option>
            <?php foreach (ErrorLog::LEVELS as $l): ?>
                <option value="<?= e($l) ?>" <?= $level === $l ? 'selected' : '' ?>><?= e(code_label('error_level', $l)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="filter-actions">
        <button type="submit" class="btn"><?= e(__('ui.action.filter')) ?></button>
        <a class="btn ghost" href="<?= e(url('/admin/errors')) ?>"><?= e(__('ui.action.clear')) ?></a>
    </div>
</form>

<form method="post" action="<?= e(url('/admin/errors/resolve')) ?>" class="card error-log" data-error-log>
    <?= Csrf::field() ?>
    <input type="hidden" name="show" value="<?= e($show) ?>">
    <input type="hidden" name="level" value="<?= e($level) ?>">
    <input type="hidden" name="q" value="<?= e($query) ?>">

    <div class="card-head">
        <h2><?= e(__('ui.label.errors_shown')) ?> (<?= count($errors) ?>)</h2>
        <?php if ($open !== []): ?>
            <span class="error-bulk">
                <label class="inline"><input type="checkbox" data-check-all> <span><?= e(__('ui.label.select_all')) ?></span></label>
                <button type="submit" class="btn small" data-needs-checked disabled><?= e(__('ui.action.resolve_selected')) ?></button>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($errors === []): ?>
        <div class="error-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 12l3 3 5-6"/></svg>
            <p><?= e(__($show === 'open' ? 'ui.message.errors_none_open' : 'ui.message.empty_list')) ?></p>
        </div>
    <?php else: ?>
        <ul class="error-list">
            <?php foreach ($errors as $err):
                $resolved = $err['resolved_at'] !== null;
                $first    = (string) strtok((string) $err['message'], "\n"); ?>
                <li id="error-<?= (int) $err['id'] ?>" class="error-item level-<?= e($err['level']) ?><?= $resolved ? ' resolved' : '' ?><?= $focus === (int) $err['id'] ? ' focus' : '' ?>">
                    <?php if (!$resolved): ?>
                        <input type="checkbox" name="ids[]" value="<?= (int) $err['id'] ?>" class="error-check" aria-label="<?= e(__('ui.label.select')) ?>">
                    <?php else: ?>
                        <span class="error-check-spacer"></span>
                    <?php endif; ?>
                    <details <?= $focus === (int) $err['id'] ? 'open' : '' ?>>
                        <summary>
                            <span class="error-head">
                                <span class="badge error-level level-<?= e($err['level']) ?>"><?= e(code_label('error_level', $err['level'])) ?></span>
                                <code class="error-message"><?= e($first) ?></code>
                            </span>
                            <span class="error-meta">
                                <?php if ($err['file']): ?><span class="error-where"><?= e($err['file']) ?><?= $err['line'] ? ':' . (int) $err['line'] : '' ?></span><?php endif; ?>
                                <span class="error-count" title="<?= e(__('ui.label.error_times')) ?>">×<?= (int) $err['count'] ?></span>
                                <span><?= e(sprintf(__('ui.label.error_last_seen'), fmt_datetime($err['last_seen'], 'd/m H:i'))) ?></span>
                                <?php if ($err['source'] === 'import'): ?><span class="badge off" title="<?= e(__('ui.label.error_imported_hint')) ?>"><?= e(__('ui.label.error_imported')) ?></span><?php endif; ?>
                                <?php if ($resolved): ?><span class="badge ok"><?= e(__('ui.label.error_resolved')) ?></span><?php endif; ?>
                            </span>
                        </summary>
                        <div class="error-detail">
                            <?php if (str_contains((string) $err['message'], "\n") || mb_strlen($first) > 140): ?>
                                <pre class="error-full"><?= e($err['message']) ?></pre>
                            <?php endif; ?>
                            <dl>
                                <dt><?= e(__('ui.label.error_first_seen')) ?></dt><dd><?= e(fmt_datetime($err['first_seen'])) ?></dd>
                                <dt><?= e(__('ui.label.error_last_request')) ?></dt>
                                <dd><?= $err['path'] ? '<code>' . e(trim(($err['method'] ?? '') . ' ' . $err['path'])) . '</code>' : '<span class="muted">' . e(__($err['source'] === 'import' ? 'ui.label.error_request_unknown' : 'ui.label.error_no_request')) . '</span>' ?>
                                    <?php if ($err['referer']): ?><small class="muted"> ← <?= e($err['referer']) ?></small><?php endif; ?></dd>
                                <?php if ($err['user_name']): ?><dt><?= e(__('ui.label.error_user')) ?></dt><dd><?= e($err['user_name']) ?></dd><?php endif; ?>
                                <?php if ($resolved): ?><dt><?= e(__('ui.label.error_resolved')) ?></dt><dd><?= e(fmt_datetime($err['resolved_at'])) ?><?= $err['resolved_by_name'] ? ' · ' . e($err['resolved_by_name']) : '' ?></dd><?php endif; ?>
                            </dl>
                            <?php if ($err['trace']): ?>
                                <pre class="error-trace"><?= e($err['trace']) ?></pre>
                            <?php endif; ?>
                            <div class="card-actions">
                                <?php if ($resolved): ?>
                                    <button type="submit" class="btn small" name="reopen_one" value="<?= (int) $err['id'] ?>"><?= e(__('ui.action.reopen')) ?></button>
                                <?php else: ?>
                                    <button type="submit" class="btn small primary" name="resolve_one" value="<?= (int) $err['id'] ?>"><?= e(__('ui.action.resolve')) ?></button>
                                <?php endif; ?>
                                <textarea id="error-copy-<?= (int) $err['id'] ?>" class="visually-hidden" readonly tabindex="-1" aria-hidden="true"><?= e($err['message'] . ($err['file'] ? "\n" . $err['file'] . ':' . $err['line'] : '') . ($err['trace'] ? "\n" . $err['trace'] : '')) ?></textarea>
                                <button type="button" class="btn small ghost" data-copy="#error-copy-<?= (int) $err['id'] ?>"><?= e(__('ui.action.copy')) ?></button>
                            </div>
                        </div>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</form>

<?php if ($show !== 'open'): ?>
    <form method="post" action="<?= e(url('/admin/errors/clear')) ?>" class="error-clear">
        <?= Csrf::field() ?>
        <button type="submit" class="btn small danger-outline" data-confirm="<?= e(__('ui.message.errors_clear_confirm')) ?>"><?= e(__('ui.action.clear_resolved')) ?></button>
    </form>
<?php endif; ?>
