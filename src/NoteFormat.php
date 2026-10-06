<?php
declare(strict_types=1);

/**
 * The light formatting of notification texts, a small part of Markdown:
 *
 *   **bold**  *italic*  ~~struck~~  `code`  [words](https://… or /page)
 *   ## heading     - list item     1. numbered item     > quote
 *
 * A blank line starts a new paragraph, a single one breaks the line, and
 * bare web addresses become links. Everything is escaped first, so the
 * only HTML that comes out is the handful of tags made here.
 */
final class NoteFormat
{
    /** The text as safe HTML. */
    public static function html(string $text): string
    {
        $lines  = preg_split('/\R/u', trim($text)) ?: [];
        $html   = [];
        $block  = null;
        $buffer = [];

        $flush = static function () use (&$html, &$block, &$buffer): void {
            if ($block === null) {
                return;
            }

            $html[] = match ($block) {
                'p'          => '<p>' . implode('<br>', $buffer) . '</p>',
                'quote'      => '<blockquote>' . implode('<br>', $buffer) . '</blockquote>',
                'ul', 'ol'   => "<{$block}>" . implode('', array_map(static fn (string $i): string => "<li>{$i}</li>", $buffer)) . "</{$block}>",
            };
            $block  = null;
            $buffer = [];
        };

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (trim($line) === '') {
                $flush();
                continue;
            }

            if (preg_match('/^\s*#{1,6}\s+(.+)$/u', $line, $m)) {
                $flush();
                $html[] = '<h4>' . self::inline($m[1]) . '</h4>';
                continue;
            }

            [$kind, $content] = match (true) {
                (bool) preg_match('/^\s*[-*•]\s+(.*)$/u', $line, $m)  => ['ul', $m[1]],
                (bool) preg_match('/^\s*\d+[.)]\s+(.*)$/u', $line, $m) => ['ol', $m[1]],
                (bool) preg_match('/^\s*>\s?(.*)$/u', $line, $m)       => ['quote', $m[1]],
                default                                                => ['p', $line],
            };

            if ($block !== $kind) {
                $flush();
                $block = $kind;
            }

            $buffer[] = self::inline($content);
        }

        $flush();

        return implode('', $html);
    }

    /** The text without its marks, on one line (headings, items and paragraphs apart by " · "): for the bell's short preview. */
    public static function plain(string $text): string
    {
        $text = (string) preg_replace_callback('/\\\\([*~`\[\]_\\\\])/u', static fn (array $m): string => "\x01" . ord($m[1]) . "\x02", $text);
        $text = (string) preg_replace('/^\s*(#{1,6})\s+(.*)$/mu', "\x03\$2\x03", $text);
        $text = (string) preg_replace('/^\s*(>|[-*•]|\d+[.)])\s+/mu', "\x03", $text);
        $text = (string) preg_replace('/\R\s*\R/u', "\x03", $text);
        $text = (string) preg_replace('/\[([^\]\n]+)\]\(([^)\s]+)\)/u', '$1', $text);
        $text = (string) preg_replace('/(\*\*|~~|`)(.+?)\1/u', '$2', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '$1', $text);
        $text = (string) preg_replace_callback("/\x01(\d+)\x02/", static fn (array $m): string => chr((int) $m[1]), $text);

        $text = trim((string) preg_replace('/\s+/u', ' ', $text), " \x03");

        return (string) preg_replace('/\s*(\x03\s*)+/u', ' · ', $text);
    }

    /** Words put into a text (a name, a title) with their marks made harmless. */
    public static function escape(string $text): string
    {
        return (string) preg_replace('/([*~`\[\]_\\\\])/u', '\\\\$1', $text);
    }

    /** One line's formatting: code and links set aside first, then emphasis, then bare addresses. */
    private static function inline(string $text): string
    {
        $kept = [];
        $keep = static function (string $html) use (&$kept): string {
            $kept[] = $html;

            return "\x01" . (count($kept) - 1) . "\x02";
        };

        $text = (string) preg_replace_callback('/\\\\([*~`\[\]_\\\\])/u', static fn (array $m): string => $keep(e($m[1])), $text);
        $text = (string) preg_replace_callback('/`([^`\n]+)`/u', static fn (array $m): string => $keep('<code>' . e($m[1]) . '</code>'), $text);
        $text = (string) preg_replace_callback(
            '/\[([^\]\n]+)\]\(([^)\s]+)\)/u',
            static fn (array $m): string => self::safeUrl($m[2]) === null
                ? $keep(e($m[0]))
                : $keep(self::link((string) self::safeUrl($m[2]), self::emphasis(e($m[1])))),
            $text
        );
        $text = (string) preg_replace_callback(
            '~https?://[^\s<>"\x01]+[^\s<>"\x01.,;:!?)\]]~u',
            static fn (array $m): string => $keep(self::link($m[0], e($m[0]))),
            $text
        );

        $html = self::emphasis(e($text));

        return (string) preg_replace_callback("/\x01(\d+)\x02/", static fn (array $m): string => $kept[(int) $m[1]], $html);
    }

    /** Bold, italic and struck text, on text already escaped. */
    private static function emphasis(string $html): string
    {
        $html = (string) preg_replace('/\*\*(?!\s)(.+?)(?<!\s)\*\*/u', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/~~(?!\s)(.+?)(?<!\s)~~/u', '<s>$1</s>', $html);

        return (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $html);
    }

    /** A web address (http or https) or a page of the site; anything else is not linked. */
    private static function safeUrl(string $url): ?string
    {
        if (preg_match('~^https?://[^\s]+$~iu', $url) || preg_match('~^/(?!/)[^\s]*$~u', $url)) {
            return $url;
        }

        return null;
    }

    private static function link(string $url, string $inner): string
    {
        $external = !str_starts_with($url, '/');

        return '<a href="' . e($external ? $url : url($url)) . '"' . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $inner . '</a>';
    }
}
