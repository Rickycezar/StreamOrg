<?php
/**
 * The error page: a drawing and a plain explanation of what happened
 * (403 not allowed, 404 no such page, 419 the page expired, 500 something
 * broke), a way back, and for signed-in users a link that opens the bug
 * report already filled in with the page, the error and its reference in
 * the error log. Visitors get the contact address instead.
 *
 * @var int $code @var ?int $reference the problem's id in the error log (500)
 */
$code      = in_array($code ?? 500, [403, 404, 419, 500], true) ? (int) $code : 500;
$path      = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$signedIn  = Auth::check();
$home      = url($signedIn ? '/dashboard' : '/');
$email     = (string) Config::get('app.contact_email', '');
$report    = url('/support/bug?' . http_build_query(array_filter(['page' => $path, 'code' => $code, 'ref' => $reference ?? null])));
$drawings  = [
    404 => '<circle cx="54" cy="54" r="30" class="err-line"/><path d="M76 76l26 26" class="err-line"/><path d="M44 46c2-6 18-6 20 2 1 6-8 7-9 13M55 68h.01" class="err-accent"/>',
    403 => '<rect x="30" y="52" width="60" height="46" rx="8" class="err-line"/><path d="M42 52V40a18 18 0 0 1 36 0v12" class="err-line"/><circle cx="60" cy="72" r="5" class="err-accent-fill"/><path d="M60 77v8" class="err-accent"/>',
    419 => '<circle cx="60" cy="62" r="34" class="err-line"/><path d="M60 42v20l13 9" class="err-accent"/><path d="M48 22h24M60 22v6" class="err-line"/>',
    500 => '<path d="M60 18l44 78H16z" class="err-line"/><path d="M60 46v24M60 82h.01" class="err-accent"/><path d="M96 30l8-8M100 40h10M88 22v-8" class="err-spark"/>',
];
?>
<section class="err-page err-<?= $code ?>">
    <div class="err-card">
        <div class="err-art" aria-hidden="true">
            <span class="err-code"><?= $code ?></span>
            <svg viewBox="0 0 120 120" fill="none" stroke-linecap="round" stroke-linejoin="round"><?= $drawings[$code] ?></svg>
        </div>

        <h1><?= e(__('ui.error_page.title_' . $code)) ?></h1>
        <p class="err-text"><?= e(__('ui.error_page.text_' . $code)) ?></p>

        <div class="err-actions">
            <?php if ($code === 419): ?>
                <a class="btn primary" href="<?= e(url($path)) ?>" data-turbo="false"><?= e(__('ui.error_page.reload')) ?></a>
            <?php else: ?>
                <a class="btn primary" href="<?= e($home) ?>"><?= e(__($signedIn ? 'ui.error_page.to_dashboard' : 'ui.error_page.to_home')) ?></a>
            <?php endif; ?>
            <button type="button" class="btn" data-history-back hidden><?= e(__('ui.action.back')) ?></button>
        </div>

        <div class="err-help">
            <?php if ($signedIn): ?>
                <p><?= e(__('ui.error_page.report_lead_' . ($code === 500 ? 'broken' : 'other'))) ?></p>
                <a class="err-report" href="<?= e($report) ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6V4.5a4 4 0 0 1 8 0V6M6 6h12a1 1 0 0 1 1 1v6a7 7 0 0 1-14 0V7a1 1 0 0 1 1-1zM12 13v6M2 13h3M19 13h3"/></svg>
                    <?= e(__('ui.error_page.report')) ?>
                </a>
            <?php elseif ($email !== ''): ?>
                <p><?= e(__('ui.error_page.write_us')) ?> <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></p>
            <?php endif; ?>
            <?php if (!empty($reference)): ?>
                <p class="err-ref"><?= e(__('ui.error_page.reference')) ?> <code>#<?= (int) $reference ?></code></p>
            <?php endif; ?>
        </div>
    </div>
</section>
