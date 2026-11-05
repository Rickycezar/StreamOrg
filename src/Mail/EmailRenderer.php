<?php
declare(strict_types=1);

/**
 * Turns a system message (EmailTemplates) into an e-mail: its subject, an
 * HTML version and a plain-text one.
 *
 * The HTML is built the way mail clients still need: tables for layout,
 * every style inline, 600px wide, a button drawn as a table cell (so
 * Outlook shows it too), the preview line hidden at the top, and the
 * link written out under the button for clients that hide buttons. It
 * keeps to a calm, light design that dark-mode clients can adapt: the
 * wordmark, a card with a small label saying what the e-mail is about,
 * the heading, the text, one button and the team's sign-off, then the
 * fine print under a thin line.
 *
 * Texts are plain: placeholders are filled and escaped, **words** become
 * bold, blank lines start a new paragraph. Values never carry HTML.
 */
final class EmailRenderer
{
    private const ACCENT = '#1f5aa6';
    private const INK = '#1d1b26';
    private const TEXT = '#3d3b48';
    private const MUTED = '#77748a';
    private const PAGE = '#f3f4f7';
    private const LINE = '#e6e8ee';
    private const FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    /**
     * @param array<string, string|int> $values the placeholders' values
     * @param ?string $link where the button goes (absolute https URL)
     * @param ?string $unsubscribe a one-click opt-out link (notification copies)
     * @return array{subject:string, html:string, text:string}
     */
    public static function render(string $code, string $locale, array $values, ?string $link = null, ?string $unsubscribe = null, ?array $texts = null): array
    {
        $texts  = $texts ?? EmailTemplates::texts($code, $locale);
        $values = ['site' => 'StreamOrg'] + array_map('strval', $values);
        $plain  = static fn (string $text): string => self::fill($text, $values);
        $base   = rtrim(public_base_url($locale), '/');
        $button = EmailTemplates::CODES[$code]['button'] && $link !== null && $texts['button'] !== '';

        $subject = $plain($texts['subject']);
        $kind    = (string) EmailTemplates::CODES[$code]['kind'];
        $signoff = $kind === 'notification' ? '' : Lang::t('ui.email_layout.signoff', $locale);
        $html    = self::html($locale, [
            'kind'      => Lang::t('ui.email_layout.kind_' . $kind, $locale),
            'signoff'   => $signoff,
            'preheader' => $plain($texts['preheader']),
            'heading'   => $plain($texts['heading']),
            'body'      => self::paragraphs($plain($texts['body'])),
            'button'    => $button ? $plain($texts['button']) : '',
            'link'      => $button ? $link : '',
            'note'      => self::inline($plain($texts['note'])),
        ], $base, $unsubscribe);

        $text = trim($plain($texts['heading'])) . "\n\n" . str_replace('**', '', trim($plain($texts['body'])));

        if ($button) {
            $text .= "\n\n" . $plain($texts['button']) . ":\n" . $link;
        }
        if ($signoff !== '') {
            $text .= "\n\n" . $signoff;
        }
        if (trim($texts['note']) !== '') {
            $text .= "\n\n" . str_replace('**', '', $plain($texts['note']));
        }

        $text .= "\n\n--\n" . Lang::t('ui.email_layout.footer_reason', $locale) . "\n" . $base;

        if ($unsubscribe !== null) {
            $text .= "\n" . Lang::t('ui.email_layout.unsubscribe', $locale) . ': ' . $unsubscribe;
        }

        return ['subject' => mb_substr(trim($subject), 0, 200), 'html' => $html, 'text' => $text . "\n"];
    }

    /** A text with its {placeholders} filled (plain text; unknown ones stay as they are). */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $m): string => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0], $text);
    }

    /** Escaped text with **bold** and line breaks. */
    private static function inline(string $text): string
    {
        $html = htmlspecialchars(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong style="color:' . self::INK . ';font-weight:700;">$1</strong>', $html);

        return nl2br($html, false);
    }

    /** @return list<string> the body's paragraphs, each as inline HTML */
    private static function paragraphs(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/', trim(str_replace("\r\n", "\n", $text))) ?: [];

        return array_values(array_filter(array_map(static fn (string $p): string => self::inline($p), $paragraphs), static fn (string $p): bool => $p !== ''));
    }

    /** @param array{kind:string, preheader:string, heading:string, body:list<string>, button:string, link:string, note:string, signoff:string} $c */
    private static function html(string $locale, array $c, string $base, ?string $unsubscribe): string
    {
        $e    = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $font = 'font-family:' . self::FONT . ';';
        $lang = $e(str_starts_with($locale, 'pt') ? 'pt-BR' : $locale);
        $body = '';

        foreach ($c['body'] as $paragraph) {
            $body .= '<p style="margin:0 0 16px;' . $font . 'font-size:16px;line-height:26px;color:' . self::TEXT . ';">' . $paragraph . '</p>';
        }

        $button = $c['button'] === '' ? '' : '<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:10px 0 6px;"><tr>'
            . '<td align="center" bgcolor="' . self::ACCENT . '" style="border-radius:8px;background:' . self::ACCENT . ';">'
            . '<a href="' . $e($c['link']) . '" target="_blank" style="display:inline-block;padding:13px 26px;' . $font . 'font-size:15px;font-weight:700;line-height:20px;color:#ffffff;text-decoration:none;border-radius:8px;">'
            . $e($c['button']) . '</a></td></tr></table>';

        $signoff = $c['signoff'] === '' ? '' : '<p style="margin:22px 0 0;' . $font . 'font-size:15px;line-height:24px;color:' . self::TEXT . ';">' . $e($c['signoff']) . '</p>';

        $fine = '';

        if ($c['note'] !== '') {
            $fine .= '<p style="margin:0 0 12px;' . $font . 'font-size:13px;line-height:21px;color:' . self::MUTED . ';">' . $c['note'] . '</p>';
        }
        if ($c['button'] !== '') {
            $fine .= '<p style="margin:0;' . $font . 'font-size:12px;line-height:19px;color:' . self::MUTED . ';">'
                . $e(Lang::t('ui.email_layout.link_fallback', $locale)) . '<br>'
                . '<a href="' . $e($c['link']) . '" target="_blank" style="color:' . self::MUTED . ';text-decoration:underline;word-break:break-all;">' . $e($c['link']) . '</a></p>';
        }
        if ($fine !== '') {
            $fine = '<tr><td class="so-pad" style="padding:0 40px 32px;">'
                . '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td style="border-top:1px solid ' . self::LINE . ';padding-top:20px;">'
                . $fine . '</td></tr></table></td></tr>';
        }

        $footer = $e(Lang::t('ui.email_layout.footer_reason', $locale))
            . '<br><a href="' . $e($base) . '" target="_blank" style="color:' . self::MUTED . ';text-decoration:none;">' . $e((string) preg_replace('#^https?://#', '', $base)) . '</a>';

        if ($unsubscribe !== null) {
            $footer .= ' &nbsp;&middot;&nbsp; <a href="' . $e($unsubscribe) . '" target="_blank" style="color:' . self::MUTED . ';text-decoration:underline;">' . $e(Lang::t('ui.email_layout.unsubscribe', $locale)) . '</a>';
        }

        return '<!DOCTYPE html><html lang="' . $lang . '" xmlns="http://www.w3.org/1999/xhtml"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="x-apple-disable-message-reformatting"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
            . '<title>' . $e($c['heading']) . '</title>'
            . '<style>@media (max-width: 480px) { .so-pad { padding-left: 24px !important; padding-right: 24px !important; } .so-h1 { font-size: 23px !important; line-height: 30px !important; } }</style></head>'
            . '<body style="margin:0;padding:0;background:' . self::PAGE . ';-webkit-text-size-adjust:100%;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $e($c['preheader']) . str_repeat('&#8199;&#65279;&#847; ', 30) . '</div>'
            . '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="background:' . self::PAGE . ';"><tr><td align="center" style="padding:36px 12px 40px;">'
            . '<table role="presentation" width="560" border="0" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;">'
            . '<tr><td style="padding:0 4px 16px;' . $font . 'font-size:17px;font-weight:800;letter-spacing:-0.2px;color:' . self::INK . ';">Stream<span style="color:' . self::ACCENT . ';">Org</span></td></tr>'
            . '<tr><td style="background:#ffffff;border:1px solid ' . self::LINE . ';border-radius:12px;">'
            . '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0">'
            . '<tr><td class="so-pad" style="padding:36px 40px 30px;">'
            . '<p style="margin:0 0 10px;' . $font . 'font-size:11px;line-height:16px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:' . self::ACCENT . ';">' . $e($c['kind']) . '</p>'
            . '<h1 class="so-h1" style="margin:0 0 20px;' . $font . 'font-size:26px;line-height:33px;font-weight:700;letter-spacing:-0.3px;color:' . self::INK . ';">' . $e($c['heading']) . '</h1>'
            . $body . $button . $signoff
            . '</td></tr>' . $fine . '</table></td></tr>'
            . '<tr><td style="padding:22px 24px 0;' . $font . 'font-size:12px;line-height:19px;color:' . self::MUTED . ';text-align:center;">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
