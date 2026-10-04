<?php
/** @var array $user @var bool $vaultLocked @var array $sessions @var array $recent
 *  @var ?int $currentId @var int $idleMinutes */
?>
<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.active_sessions')) ?> (<?= count($sessions) ?>)</h2>
        <?php if (count($sessions) > 1): ?>
            <form method="post" action="<?= e(url('/profile/sessions/revoke-others')) ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn danger-btn"
                        data-confirm="<?= e(__('ui.message.confirm_revoke_others')) ?>">
                    <?= e(__('ui.action.revoke_other_sessions')) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
    <p class="muted small"><?= e(sprintf(__('ui.message.sessions_explain'), $idleMinutes)) ?></p>

    <div class="table-wrap">
        <table class="sessions">
            <thead>
            <tr>
                <th><?= e(__('ui.field.device')) ?></th>
                <th><?= e(__('ui.field.ip_address')) ?></th>
                <th><?= e(__('ui.field.signed_in_at')) ?></th>
                <th><?= e(__('ui.field.last_active')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sessions as $s): ?>
                <?php $mine = (int) $s['id'] === $currentId; ?>
                <tr class="<?= $mine ? 'current' : '' ?>">
                    <td title="<?= e((string) $s['user_agent']) ?>">
                        <?= e(UserSessions::describe($s['user_agent'])) ?>
                        <?php if ($mine): ?>
                            <span class="badge ok"><?= e(__('ui.label.this_session')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap"><?= e($s['ip'] ?? '—') ?></td>
                    <td class="dates"><?= e(fmt_datetime($s['created_at'])) ?></td>
                    <td class="dates"><?= e(fmt_datetime($s['last_seen_at'])) ?></td>
                    <td class="rowactions">
                        <form method="post" action="<?= e(url('/profile/sessions/revoke')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="btn small"
                                    <?= $mine ? 'data-confirm="' . e(__('ui.message.confirm_revoke_current')) . '"' : '' ?>>
                                <?= e(__('ui.action.sign_out')) ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($recent !== []): ?>
<section class="card">
    <h2><?= e(__('ui.label.recent_sessions')) ?></h2>
    <p class="muted small"><?= e(__('ui.message.recent_sessions_explain')) ?></p>

    <div class="table-wrap">
        <table class="sessions">
            <thead>
            <tr>
                <th><?= e(__('ui.field.device')) ?></th>
                <th><?= e(__('ui.field.ip_address')) ?></th>
                <th><?= e(__('ui.field.signed_in_at')) ?></th>
                <th><?= e(__('ui.field.ended_at')) ?></th>
                <th><?= e(__('ui.field.ended_how')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $s): ?>
                <tr>
                    <td title="<?= e((string) $s['user_agent']) ?>"><?= e(UserSessions::describe($s['user_agent'])) ?></td>
                    <td class="nowrap"><?= e($s['ip'] ?? '—') ?></td>
                    <td class="dates"><?= e(fmt_datetime($s['created_at'])) ?></td>
                    <td class="dates"><?= e(fmt_datetime($s['ended_at'])) ?></td>
                    <td><?= e(code_label('session_end', $s['reason'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="card" id="vault">
    <h2><?= e(__('ui.label.key_vault')) ?></h2>

    <?php $private = $user['vault_mode'] === 'private'; ?>

    <p>
        <strong><?= e(__($private ? 'ui.label.vault_private' : 'ui.label.vault_managed')) ?></strong> —
        <?= e(__($private ? 'ui.message.vault_private_explain' : 'ui.message.vault_managed_explain')) ?>
    </p>

    <?php if ($vaultLocked): ?>
        <?php $back = '/profile/security'; require dirname(__DIR__) . '/partials/vault_unlock.php'; ?>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/profile/vault')) ?>" class="subform" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="vault_mode" value="<?= $private ? 'managed' : 'private' ?>">

        <?php if (!$private): ?>
            <div class="flash flash-error"><?= e(__('ui.message.vault_private_warning')) ?></div>
            <label class="inline">
                <input type="checkbox" name="accept_loss" value="1" required>
                <span><?= e(__('ui.field.vault_accept_loss')) ?></span>
            </label>
        <?php else: ?>
            <p class="muted small"><?= e(__('ui.message.vault_managed_back')) ?></p>
        <?php endif; ?>

        <label>
            <span><?= e(__('ui.field.current_password')) ?></span>
            <input type="password" name="current_password" autocomplete="current-password" required>
        </label>
        <button type="submit" class="btn <?= $private ? '' : 'danger-btn' ?>">
            <?= e(__($private ? 'ui.action.vault_make_managed' : 'ui.action.vault_make_private')) ?>
        </button>
    </form>
</section>
