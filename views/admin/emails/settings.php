<?php
/**
 * E-mail settings: the mode (off, preview, live), who e-mails come from,
 * the daily cap, the provider (Postmark), what to set up in DNS before
 * going live, and the addresses written to no more.
 *
 * @var string $tab @var string $mode @var array{name:string, address:string, reply_to:string} $sender
 * @var int $perDay @var bool $hasToken @var string $stream @var list<array> $suppressions
 */
require __DIR__ . '/_tabs.php';
$domain = $sender['address'] !== '' ? substr($sender['address'], strpos($sender['address'], '@') + 1) : 'notify.example.com';
?>
<form class="card" method="post" action="<?= e(url('/admin/emails/settings')) ?>" autocomplete="off">
    <?= Csrf::field() ?>
    <h2><?= e(__('ui.email_admin.mode')) ?></h2>
    <div class="email-modes">
        <?php foreach (Mailer::MODES as $m): ?>
            <label class="email-mode-option">
                <input type="radio" name="mode" value="<?= e($m) ?>" <?= $mode === $m ? 'checked' : '' ?> <?= $m === 'live' && !$hasToken ? 'disabled' : '' ?>>
                <span>
                    <strong><?= e(__('ui.email_admin.mode_' . $m)) ?></strong>
                    <small class="muted"><?= e(__('ui.email_admin.mode_' . $m . '_hint')) ?></small>
                </span>
            </label>
        <?php endforeach; ?>
    </div>

    <h2><?= e(__('ui.email_admin.sender')) ?></h2>
    <div class="grid">
        <label>
            <span><?= e(__('ui.email_admin.from_name')) ?></span>
            <input type="text" name="from_name" value="<?= e($sender['name']) ?>" maxlength="60">
        </label>
        <label>
            <span><?= e(__('ui.email_admin.from_address')) ?></span>
            <input type="email" name="from_address" value="<?= e($sender['address']) ?>" placeholder="no-reply@notify.streamorg.com">
            <small class="muted"><?= e(__('ui.email_admin.from_address_hint')) ?></small>
        </label>
        <label>
            <span><?= e(__('ui.email_admin.reply_to')) ?> <small class="muted"><?= e(__('ui.label.optional')) ?></small></span>
            <input type="email" name="reply_to" value="<?= e($sender['reply_to']) ?>" placeholder="streamorg@outlook.com">
        </label>
        <label>
            <span><?= e(__('ui.email_admin.per_day')) ?></span>
            <input type="number" name="per_day" min="1" max="100000" value="<?= (int) $perDay ?>" required>
            <small class="muted"><?= e(sprintf(__('ui.email_admin.per_day_hint'), Mailer::PER_HOUR)) ?></small>
        </label>
    </div>

    <h2><?= e(__('ui.email_admin.provider')) ?></h2>
    <p class="muted small"><?= e(__('ui.email_admin.provider_hint')) ?></p>
    <div class="grid">
        <label>
            <span><?= e(__('ui.email_admin.postmark_token')) ?></span>
            <input type="password" name="postmark_token" value="" placeholder="<?= e(__($hasToken ? 'ui.email_admin.token_kept' : 'ui.email_admin.token_none')) ?>" autocomplete="new-password">
        </label>
        <label>
            <span><?= e(__('ui.email_admin.postmark_stream')) ?></span>
            <input type="text" name="postmark_stream" value="<?= e($stream) ?>" maxlength="40">
        </label>
    </div>
    <?php if ($hasToken): ?>
        <label class="inline"><input type="checkbox" name="postmark_clear" value="1"><span><?= e(__('ui.email_admin.token_clear')) ?></span></label>
    <?php endif; ?>

    <div class="card-actions">
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
        <button type="submit" class="btn" form="email-test"><?= e(__('ui.email_admin.send_test')) ?></button>
    </div>
</form>
<form id="email-test" method="post" action="<?= e(url('/admin/emails/test')) ?>" hidden>
    <?= Csrf::field() ?>
    <input type="hidden" name="code" value="test">
    <input type="hidden" name="locale" value="<?= e(Lang::locale()) ?>">
</form>

<section class="card">
    <h2><?= e(__('ui.email_admin.dns')) ?></h2>
    <p class="muted small"><?= e(__('ui.email_admin.dns_intro')) ?></p>
    <ol class="email-dns">
        <li><strong>SPF</strong> — <?= e(__('ui.email_admin.dns_spf')) ?><br><code>TXT <?= e($domain) ?> "v=spf1 include:spf.mtasv.net ~all"</code></li>
        <li><strong>DKIM</strong> — <?= e(__('ui.email_admin.dns_dkim')) ?></li>
        <li><strong>Return-Path</strong> — <?= e(__('ui.email_admin.dns_return')) ?><br><code>CNAME pm-bounces.<?= e($domain) ?> pm.mtasv.net</code></li>
        <li><strong>DMARC</strong> — <?= e(__('ui.email_admin.dns_dmarc')) ?><br><code>TXT _dmarc.<?= e($domain) ?> "v=DMARC1; p=none; rua=mailto:dmarc@<?= e($domain) ?>"</code></li>
        <li><?= e(__('ui.email_admin.dns_worker')) ?><br><code>* * * * * php bin/mail_worker.php</code></li>
    </ol>
</section>

<section class="card" id="suppressions">
    <h2><?= e(__('ui.email_admin.suppressions')) ?> (<?= count($suppressions) ?>)</h2>
    <p class="muted small"><?= e(__('ui.email_admin.suppressions_hint')) ?></p>
    <form class="email-suppress-add" method="post" action="<?= e(url('/admin/emails/suppressions')) ?>">
        <?= Csrf::field() ?>
        <input type="email" name="address" required placeholder="email@example.com">
        <button type="submit" class="btn small"><?= e(__('ui.email_admin.suppress_add')) ?></button>
    </form>
    <?php if ($suppressions !== []): ?>
        <ul class="email-suppressions">
            <?php foreach ($suppressions as $s): ?>
                <li>
                    <span><?= e($s['address']) ?> <small class="muted"><?= e(__('ui.email_admin.reason_' . $s['reason'])) ?> · <?= e(fmt_date($s['created_at'])) ?></small></span>
                    <form method="post" action="<?= e(url('/admin/emails/suppressions')) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="address" value="<?= e($s['address']) ?>">
                        <input type="hidden" name="action" value="remove">
                        <button type="submit" class="btn small ghost"><?= e(__('ui.action.remove')) ?></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
