<?php
declare(strict_types=1);

/**
 * Inline SVG charts.
 *
 * No library and no canvas: the markup is generated server-side, scales
 * with the page, prints, and inherits the stylesheet's colours. Every
 * chart here plots one measure across a handful of categories, so they
 * are all single-hue bars — colour carries no information the bar length
 * does not already carry, which keeps them readable for everyone.
 *
 * Mark specs: bars <= 24px thick with a 4px rounded data-end and a square
 * baseline, hairline gridlines one step off the surface, values direct-
 * labelled at the tip, and category text in ink rather than the data
 * colour.
 */
final class Chart
{
    /**
     * Emitted as CSS variables rather than hex so the charts follow the
     * active theme; the light values were validated as a categorical pair
     * and each theme restates them for its own surface.
     */
    public const INK      = 'var(--chart-ink)';
    public const INK_SOFT = 'var(--chart-ink-soft)';
    private const GRID    = 'var(--chart-grid)';
    private const TEXT    = 'var(--text-2)';
    private const MUTED   = 'var(--muted)';

    private const BAR_H   = 18;
    private const ROW_H   = 30;
    private const RADIUS  = 4;

    /**
     * Horizontal bars, one per category. Horizontal because the category
     * names are words, not dates — they read straight and need no rotation.
     *
     * @param list<array{label:string, value:int|float, note?:string}> $rows
     */
    public static function bars(array $rows, int $gutter = 124, int $width = 424): string
    {
        if ($rows === []) {
            return '';
        }

        $max    = max(1, (int) max(array_column($rows, 'value')));
        $plotL  = $gutter;
        $plotR  = $width - 44;
        $span   = max(1, $plotR - $plotL);
        $height = count($rows) * self::ROW_H + 8;

        $svg = sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" role="img" preserveAspectRatio="xMinYMin meet">',
            $width,
            $height,
        );

        foreach ([0.5, 1.0] as $frac) {
            $x = $plotL + (int) round($span * $frac);
            $svg .= sprintf(
                '<line x1="%d" y1="2" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>',
                $x, $x, $height - 8, self::GRID,
            );
        }

        foreach (array_values($rows) as $i => $row) {
            $y     = $i * self::ROW_H + 4;
            $barY  = $y + (int) ((self::ROW_H - self::BAR_H) / 2);
            $value = (float) $row['value'];
            $w     = $max > 0 ? (int) round($span * ($value / $max)) : 0;

            $svg .= sprintf(
                '<text x="%d" y="%d" text-anchor="end" fill="%s" font-size="12">%s</text>',
                $gutter - 12,
                $barY + 13,
                self::MUTED,
                e(mb_strimwidth($row['label'], 0, 19, '…')),
            );

            if ($w > 0) {
                $svg .= sprintf(
                    '<path d="%s" fill="%s"><title>%s</title></path>',
                    self::barPath($plotL, $barY, $w, self::BAR_H),
                    self::INK,
                    e(($row['note'] ?? $row['label']) . ': ' . self::num($value)),
                );
            }

            $svg .= sprintf(
                '<text x="%d" y="%d" fill="%s" font-size="12" font-weight="600">%s</text>',
                $plotL + $w + 8,
                $barY + 13,
                self::TEXT,
                self::num($value),
            );
        }

        return $svg . '</svg>';
    }

    /**
     * One bar split into parts. Used for a part-to-whole where the split
     * itself is the story; segments are separated by a 2px surface gap
     * rather than a stroke.
     *
     * @param list<array{label:string, value:int|float, color?:string}> $parts
     */
    public static function split(array $parts, int $width = 640): string
    {
        $total = array_sum(array_column($parts, 'value'));

        if ($total <= 0) {
            return '';
        }

        $height = 30;
        $gap    = 2;
        $usable = $width - $gap * max(0, count($parts) - 1);

        $svg = sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" role="img" preserveAspectRatio="none">',
            $width,
            $height,
        );

        $x = 0;

        foreach (array_values($parts) as $i => $part) {
            $w = (int) round($usable * ((float) $part['value'] / $total));

            if ($w <= 0) {
                continue;
            }

            $colour = $part['color'] ?? ($i === 0 ? self::INK : self::INK_SOFT);
            $label  = self::num((float) $part['value']);

            $svg .= sprintf(
                '<rect x="%d" y="0" width="%d" height="%d" rx="3" fill="%s"><title>%s</title></rect>',
                $x, $w, $height, $colour,
                e($part['label'] . ': ' . $label),
            );

            if ($w > 42) {
                $svg .= sprintf(
                    '<text x="%d" y="19" text-anchor="middle" fill="var(--chart-label)" font-size="12" font-weight="600">%s</text>',
                    $x + (int) ($w / 2),
                    $label,
                );
            }

            $x += $w + $gap;
        }

        return $svg . '</svg>';
    }

    /** Rounded at the data end, square against the baseline. */
    private static function barPath(int $x, int $y, int $w, int $h): string
    {
        $r = min(self::RADIUS, (int) floor($w / 2));

        if ($r < 1) {
            return sprintf('M%d %d h%d v%d h-%d Z', $x, $y, $w, $h, $w);
        }

        return sprintf(
            'M%d %d H%d a%d %d 0 0 1 %d %d V%d a%d %d 0 0 1 -%d %d H%d Z',
            $x, $y,
            $x + $w - $r, $r, $r, $r, $r,
            $y + $h - $r, $r, $r, $r, $r,
            $x,
        );
    }

    private static function num(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
