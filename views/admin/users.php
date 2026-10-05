<?php
/** @var array $users @var ?array $generated @var array $old @var list<string> $roles
 *  @var list<string> $locales @var list<string> $timezones @var string $defaultTz */

$value = static fn (string $key, string $default = ''): string => (string) ($old[$key] ?? $default);
?>
<h1><?= e(__('ui.nav.users')) ?></h1>

<?php if ($generated !== null): ?>
    <section class="card new-password">
        <h2><?= e(sprintf(__('ui.label.user_password_for'), $generated['username'])) ?></h2>
        <p class="muted small"><?= e(__('ui.message.user_password_once')) ?></p>
        <div class="keyfield">
            <input type="text" class="key-field" value="<?= e($generated['password']) ?>" readonly aria-label="<?= e(__('ui.field.password')) ?>">
            <button type="button" class="btn key-copy" title="<?= e(__('ui.action.copy')) ?>"><?= e(__('ui.action.copy')) ?></button>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2><?= e(sprintf(__('ui.label.n_users'), count($users))) ?></h2>
        <button type="button" class="btn primary" data-new data-modal-form="#add-user"
                data-modal-title="<?= e(__('ui.action.add_user')) ?>"><?= e(__('ui.action.add_user')) ?></button>
    </div>

    <form id="add-user" method="post" action="<?= e(url('/admin/users')) ?>" class="subform hidden" autocomplete="off">
        <?= Csrf::field() ?>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.username')) ?></span>
                <input type="text" name="username" value="<?= e($value('username')) ?>" required
                       pattern="[A-Za-z0-9_.\-]{3,32}" maxlength="32" autocomplete="off">
                <small class="muted"><?= e(__('ui.label.username_rules')) ?></small>
            </label>
            <label>
                <span><?= e(__('ui.field.name')) ?></span>
                <input type="text" name="name" value="<?= e($value('name')) ?>" maxlength="80">
            </label>
        </div>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.email')) ?></span>
                <input type="email" name="email" value="<?= e($value('email')) ?>" required>
            </label>
            <label>
                <span><?= e(__('ui.field.role')) ?></span>
                <select name="role">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role) ?>" <?= $value('role', 'user') === $role ? 'selected' : '' ?>><?= e(code_label('user_role', $role)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.language')) ?></span>
                <select name="locale">
                    <?php foreach ($locales as $locale): ?>
                        <option value="<?= e($locale) ?>" <?= $value('locale', Lang::locale()) === $locale ? 'selected' : '' ?>><?= e($locale) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.timezone')) ?></span>
                <select name="timezone">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?= e($tz) ?>" <?= $value('timezone', $defaultTz) === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label>
            <span><?= e(__('ui.field.password')) ?></span>
            <input type="password" name="password" minlength="10" autocomplete="new-password">
            <small class="muted"><?= e(__('ui.label.user_password_blank')) ?></small>
        </label>
        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__('ui.action.add_user')) ?></button>
            <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th><?= e(__('ui.field.username')) ?></th>
                <th><?= e(__('ui.field.email')) ?></th>
                <th><?= e(__('ui.field.role')) ?></th>
                <th><?= e(__('ui.field.language')) ?></th>
                <th>Twitch</th>
                <th><?= e(__('ui.field.last_active')) ?></th>
                <th><?= e(__('ui.field.created_at')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr class="<?= $u['is_active'] ? '' : 'muted' ?>">
                    <td>
                        <strong><?= e($u['username']) ?></strong>
                        <?php if ($u['display_name'] && $u['display_name'] !== $u['username']): ?>
                            <small class="muted"> · <?= e($u['display_name']) ?></small>
                        <?php endif; ?>
                        <?php if ((int) $u['id'] === Auth::id()): ?><span class="badge"><?= e(__('ui.label.you')) ?></span><?php endif; ?>
                    </td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge <?= $u['role'] === 'admin' ? 'early' : 'off' ?>"><?= e(code_label('user_role', $u['role'])) ?></span></td>
                    <td><?= e($u['locale']) ?></td>
                    <td><?= $u['twitch_login'] ? e($u['twitch_login']) : '<span class="muted">—</span>' ?></td>
                    <td class="nowrap"><?= $u['last_seen'] ? e(fmt_datetime($u['last_seen'])) : '<span class="muted">' . e(__('ui.label.never')) . '</span>' ?></td>
                    <td class="nowrap"><?= e(fmt_date($u['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
