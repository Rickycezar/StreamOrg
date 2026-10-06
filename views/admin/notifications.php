<?php
/**
 * Administration → Notifications: write one (a title and text per
 * language, its kind, an optional link) for everyone or chosen users, and
 * see what was sent, who it was for and how many read it.
 *
 * @var list<string> $locales @var list<array> $sent
 */
$current = Lang::locale();
?>
<h1><?= e(__('ui.nav.notifications')) ?></h1>
<p class="muted"><?= e(__('ui.message.notifications_admin_intro')) ?></p>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.notification_new')) ?></h2>
    </div>

    <form method="post" action="<?= e(url('/admin/notifications')) ?>" class="notify-compose" data-notify-compose>
        <?= Csrf::field() ?>

        <div class="lang-tabs" role="tablist">
            <?php foreach ($locales as $i => $locale): ?>
                <button type="button" role="tab" class="<?= $i === 0 ? 'active' : '' ?>" data-lang-tab="<?= e($locale) ?>"
                        aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
                    <?= e(Lang::t('ui.label.language_name', $locale)) ?>
                    <span class="lang-filled" hidden>✓</span>
                </button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($locales as $i => $locale): ?>
            <div class="lang-panel" data-lang-panel="<?= e($locale) ?>" <?= $i === 0 ? '' : 'hidden' ?>>
                <label>
                    <span><?= e(__('ui.field.title')) ?></span>
                    <input type="text" name="title[<?= e($locale) ?>]" maxlength="<?= Notifications::TITLE_MAX ?>" lang="<?= e($locale) ?>">
                </label>
                <label>
                    <span><?= e(__('ui.field.notification_body')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                    <textarea name="body[<?= e($locale) ?>]" rows="4" maxlength="<?= Notifications::BODY_MAX ?>" lang="<?= e($locale) ?>"></textarea>
                </label>
            </div>
        <?php endforeach; ?>
        <p class="muted small"><?= e(__('ui.message.notification_languages_hint')) ?></p>

        <div class="grid">
            <fieldset class="note-levels">
                <legend><?= e(__('ui.field.notification_level')) ?></legend>
                <?php foreach (Notifications::LEVELS as $i => $level): ?>
                    <label class="note-level level-<?= e($level) ?>">
                        <input type="radio" name="level" value="<?= e($level) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                        <span><?= e(__('ui.notification_level.' . $level)) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <label>
                <span><?= e(__('ui.field.notification_link')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
                <input type="text" name="link" placeholder="/content · https://…">
                <small class="muted"><?= e(__('ui.message.notification_link_hint')) ?></small>
            </label>
        </div>

        <fieldset class="note-audience">
            <legend><?= e(__('ui.field.notification_audience')) ?></legend>
            <label class="inline"><input type="radio" name="audience" value="everyone" checked data-audience> <span><?= e(__('ui.label.notification_everyone')) ?></span></label>
            <label class="inline"><input type="radio" name="audience" value="users" data-audience> <span><?= e(__('ui.label.notification_some_users')) ?></span></label>
            <div class="note-users" data-audience-users hidden>
                <select name="users[]" multiple data-picker="users" data-placeholder="<?= e(__('ui.label.picker_search')) ?>"></select>
            </div>
        </fieldset>

        <button type="submit" class="btn primary"><?= e(__('ui.action.send_notification')) ?></button>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.notifications_sent')) ?> (<?= count($sent) ?>)</h2>
    </div>
    <?php if ($sent === []): ?>
        <p class="empty"><?= e(__('ui.message.notifications_none_sent')) ?></p>
    <?php else: ?>
        <ul class="note-page sent">
            <?php foreach ($sent as $n):
                $text = Notifications::textIn($n['texts'], $current); ?>
                <li class="note">
                    <?= View::partial('notifications/item', ['item' => ['level' => $n['level'], 'title' => $text['title'], 'body' => $text['body'], 'created_at' => $n['created_at']], 'full' => true]) ?>
                    <div class="note-meta">
                        <span class="badge"><?= e($n['audience'] === 'everyone' ? __('ui.label.notification_everyone') : sprintf(__('ui.label.notification_n_users'), (int) $n['audience_size'])) ?></span>
                        <span class="badge ok"><?= e(sprintf(__('ui.label.notification_reads'), (int) $n['reads'], (int) $n['audience_size'])) ?></span>
                        <?php foreach (array_keys($n['texts']) as $locale): ?>
                            <span class="badge"><?= e($locale) ?></span>
                        <?php endforeach; ?>
                        <?php if ($n['recipients']): ?><small class="muted" title="<?= e($n['recipients']) ?>"><?= e(mb_strimwidth($n['recipients'], 0, 60, '…')) ?></small><?php endif; ?>
                        <?php if ($n['author']): ?><small class="muted">· <?= e($n['author']) ?></small><?php endif; ?>
                        <form method="post" action="<?= e(url('/admin/notifications/delete')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="btn small danger-btn" data-confirm="<?= e(__('ui.message.notification_delete_confirm')) ?>"><?= e(__('ui.action.take_back_notification')) ?></button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
