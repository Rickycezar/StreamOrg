<?php
/**
 * One notification: its kind's icon, title, body (formatted, see
 * NoteFormat; a plain line of it in the bell) and when it was sent.
 *
 * @var array $item @var bool $full whether to show the whole body (the page) or a line of it (the bell)
 */
$icons = [
    'info'    => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 11v5M12 8h.01',
    'news'    => 'M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1zM16 8a5 5 0 0 1 0 8M19 5a9 9 0 0 1 0 14',
    'success' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 12l3 3 5-6',
    'warning' => 'M12 3l9 16H3zM12 10v4M12 17h.01',
];
$level = isset($icons[$item['level']]) ? $item['level'] : 'info';
$body  = (string) $item['body'];
$text  = $full
    ? NoteFormat::html($body)
    : e(mb_strimwidth(NoteFormat::plain($body), 0, 110, '…'));
?>
<span class="note-icon level-<?= e($level) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= e($icons[$level]) ?>"/></svg>
</span>
<<?= $full ? 'div' : 'span' ?> class="note-text">
    <strong><?= e($item['title']) ?></strong>
    <?php if ($body !== ''): ?><<?= $full ? 'div' : 'span' ?> class="note-body<?= $full ? ' rich' : '' ?>"><?= $text ?></<?= $full ? 'div' : 'span' ?>><?php endif; ?>
    <time datetime="<?= e($item['created_at']) ?>"><?= e(fmt_datetime($item['created_at'])) ?></time>
</<?= $full ? 'div' : 'span' ?>>
<?php if (!empty($item['unread'])): ?><span class="note-dot" aria-label="<?= e(__('ui.label.unread')) ?>"></span><?php endif; ?>
