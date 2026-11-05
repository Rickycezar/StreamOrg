<?php
/**
 * Signing up as a streamer: the account waits for the address to be
 * confirmed and for an administrator's approval. The "website" field is
 * hidden from people; bots that fill it get nowhere.
 *
 * @var array<string, string> $old what was typed before an error (no passwords)
 */
$v = static fn (string $key): string => (string) ($old[$key] ?? '');
?>
<div class="login-shell">
    <div class="login-card signup-card">
        <a class="login-back" href="<?= e(url('/login')) ?>">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            <?= e(__('ui.action.back')) ?>
        </a>
        <?php $class = 'login-mark'; $w = 84; $h = 70; require dirname(__DIR__) . '/partials/logo.php'; ?>
        <h1><?= e(__('ui.signup.title')) ?></h1>
        <form method="post" action="<?= e(url('/signup')) ?>" data-turbo="false" autocomplete="on">
            <?= Csrf::field() ?>
            <p class="muted login-intro"><?= e(__(Signups::autoApprove() ? 'ui.signup.intro_open' : 'ui.signup.intro')) ?></p>
            <label>
                <span><?= e(__('ui.signup.name')) ?></span>
                <input type="text" name="name" value="<?= e($v('name')) ?>" maxlength="80" autocomplete="name" required autofocus>
            </label>
            <label>
                <span><?= e(__('ui.field.username')) ?></span>
                <input type="text" name="username" value="<?= e($v('username')) ?>" pattern="[A-Za-z0-9_.\-]{3,32}" maxlength="32" autocomplete="username" required>
                <small class="muted"><?= e(__('ui.signup.username_hint')) ?></small>
            </label>
            <label>
                <span><?= e(__('ui.field.email')) ?></span>
                <input type="email" name="email" value="<?= e($v('email')) ?>" maxlength="254" autocomplete="email" required>
            </label>
            <label>
                <span><?= e(__('ui.signup.channel')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                <span class="input-prefix"><span>twitch.tv/</span><input type="text" name="channel" value="<?= e($v('channel')) ?>" pattern="[A-Za-z0-9_]{3,25}" maxlength="25" autocomplete="off"></span>
            </label>
            <label>
                <span><?= e(__('ui.field.password')) ?></span>
                <input type="password" name="password" minlength="10" autocomplete="new-password" required>
                <small class="muted"><?= e(__('ui.signup.password_hint')) ?></small>
            </label>
            <label>
                <span><?= e(__('ui.field.confirm_password')) ?></span>
                <input type="password" name="confirm_password" minlength="10" autocomplete="new-password" required>
            </label>
            <label class="signup-trap" aria-hidden="true">
                <span>Website</span>
                <input type="text" name="website" value="" tabindex="-1" autocomplete="off">
            </label>
            <input type="hidden" name="timezone" value="<?= e($v('timezone')) ?>" data-timezone-guess>
            <label class="inline">
                <input type="checkbox" name="accept_privacy" value="1" required>
                <span><?= e(__('ui.signup.accept_before')) ?> <a href="<?= e(url('/privacy')) ?>" target="_blank" rel="noopener"><?= e(__('ui.signup.accept_link')) ?></a></span>
            </label>
            <button type="submit" class="btn primary wide"><?= e(__('ui.signup.submit')) ?></button>
            <?php if (!Signups::autoApprove()): ?><p class="muted small login-note"><?= e(__('ui.signup.approval_note')) ?></p><?php endif; ?>
        </form>
    </div>
</div>
