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
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr class="<?= $u['is_active'] ? '' : 'user-inactive' ?>">
                    <td class="user-cell">
                        <?php $avatarUser = $u; $avatarSize = 'sm'; require dirname(__DIR__) . '/partials/avatar.php'; ?>
                        <strong><?= e($u['username']) ?></strong>
                        <?php if ($u['display_name'] && $u['display_name'] !== $u['username']): ?>
                            <small class="muted"> · <?= e($u['display_name']) ?></small>
                        <?php endif; ?>
                        <?php if ((int) $u['id'] === Auth::id()): ?><span class="badge"><?= e(__('ui.label.you')) ?></span><?php endif; ?>
                        <?php if (!$u['is_active']): ?><span class="badge warn"><?= e(__('ui.label.inactive')) ?></span><?php endif; ?>
                        <?php if ($u['vault_mode'] === 'private'): ?><span class="badge" title="<?= e(__('ui.label.vault_private')) ?>">🔒</span><?php endif; ?>
                    </td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge <?= $u['role'] === 'admin' ? 'early' : 'off' ?>"><?= e(code_label('user_role', $u['role'])) ?></span></td>
                    <td><?= e($u['locale']) ?></td>
                    <td><?= $u['twitch_login'] ? e($u['twitch_login']) : '<span class="muted">—</span>' ?></td>
                    <td class="nowrap"><?= $u['last_seen'] ? e(fmt_datetime($u['last_seen'])) : '<span class="muted">' . e(__('ui.label.never')) . '</span>' ?></td>
                    <td class="nowrap"><?= e(fmt_date($u['created_at'])) ?></td>
                    <td class="rowactions">
                        <button type="button" class="btn small" data-modal-form="#edit-user-<?= (int) $u['id'] ?>"
                                data-modal-title="<?= e(sprintf(__('ui.label.edit_user'), $u['username'])) ?>"><?= e(__('ui.action.edit')) ?></button>
                        <button type="button" class="btn small" data-modal-form="#reset-user-<?= (int) $u['id'] ?>"
                                data-modal-title="<?= e(sprintf(__('ui.label.reset_password_for'), $u['username'])) ?>"><?= e(__('ui.action.reset_password')) ?></button>
                        <?php if ((int) $u['id'] !== Auth::id()): ?>
                            <button type="button" class="btn small danger-btn" data-modal-form="#delete-user-<?= (int) $u['id'] ?>"
                                    data-modal-title="<?= e(sprintf(__('ui.label.delete_user'), $u['username'])) ?>"><?= e(__('ui.action.delete')) ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php foreach ($users as $u):
    $id   = (int) $u['id'];
    $self = $id === Auth::id(); ?>
    <form id="edit-user-<?= $id ?>" method="post" action="<?= e(url('/admin/users/update')) ?>" class="subform hidden" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.username')) ?></span>
                <input type="text" value="<?= e($u['username']) ?>" disabled>
            </label>
            <label>
                <span><?= e(__('ui.field.name')) ?></span>
                <input type="text" name="name" value="<?= e((string) $u['display_name']) ?>" maxlength="80">
            </label>
        </div>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.email')) ?></span>
                <input type="text" name="email" value="<?= e($u['email']) ?>" required>
            </label>
            <label>
                <span><?= e(__('ui.field.role')) ?></span>
                <select name="role" <?= $self ? 'disabled' : '' ?>>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role) ?>" <?= $u['role'] === $role ? 'selected' : '' ?>><?= e(code_label('user_role', $role)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($self): ?><input type="hidden" name="role" value="<?= e($u['role']) ?>"><?php endif; ?>
            </label>
        </div>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.language')) ?></span>
                <select name="locale">
                    <?php foreach ($locales as $locale): ?>
                        <option value="<?= e($locale) ?>" <?= $u['locale'] === $locale ? 'selected' : '' ?>><?= e($locale) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e(__('ui.field.timezone')) ?></span>
                <select name="timezone">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?= e($tz) ?>" <?= $u['timezone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label class="inline">
            <input type="checkbox" name="is_active" value="1" <?= $u['is_active'] ? 'checked' : '' ?> <?= $self ? 'disabled' : '' ?>>
            <span><?= e(__('ui.field.user_active')) ?></span>
        </label>
        <?php if ($self): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
        <p class="muted small"><?= e(__($self ? 'ui.message.user_edit_self' : 'ui.message.user_active_hint')) ?></p>
        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
            <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
        </div>
    </form>

    <form id="reset-user-<?= $id ?>" method="post" action="<?= e(url('/admin/users/password')) ?>" class="subform hidden" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <p class="muted small"><?= e(__('ui.message.user_reset_explain')) ?></p>
        <label>
            <span><?= e(__('ui.field.new_password')) ?></span>
            <input type="password" name="password" minlength="10" autocomplete="new-password">
            <small class="muted"><?= e(__('ui.label.user_password_blank')) ?></small>
        </label>
        <?php if ($u['vault_mode'] === 'private'): ?>
            <div class="flash flash-error"><?= e(__('ui.message.user_reset_private_warning')) ?></div>
            <label class="inline">
                <input type="checkbox" name="accept_loss" value="1" required>
                <span><?= e(__('ui.field.user_reset_accept')) ?></span>
            </label>
        <?php endif; ?>
        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__('ui.action.reset_password')) ?></button>
            <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
        </div>
    </form>

    <?php if (!$self): ?>
        <form id="delete-user-<?= $id ?>" method="post" action="<?= e(url('/admin/users/delete')) ?>" class="subform hidden" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="flash flash-error"><?= e(__('ui.message.user_delete_warning')) ?></div>
            <p class="muted small"><?= e(__('ui.message.user_delete_alternative')) ?></p>
            <label>
                <?php [$typeBefore, $typeAfter] = array_pad(explode('%s', __('ui.field.user_delete_type'), 2), 2, ''); ?>
                <span><?= e($typeBefore) ?><code class="confirm-name"><?= e($u['username']) ?></code><?= e($typeAfter) ?></span>
                <input type="text" name="confirm" required autocomplete="off" spellcheck="false">
            </label>
            <div class="card-actions">
                <button type="submit" class="btn danger-btn"><?= e(__('ui.action.delete_forever')) ?></button>
                <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
            </div>
        </form>
    <?php endif; ?>
<?php endforeach; ?>
