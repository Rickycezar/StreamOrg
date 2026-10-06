<?php
/**
 * The small drawings of the "How it works" pages: simplified pieces of the
 * app (cards, calendar, keys…) drawn with the theme's colours, so they
 * suit light and dark themes alike and need no translation.
 *
 * @var string $art "<page>.<section>"
 */

/** A rounded rectangle; $c is its class (colour). */
$r = static fn (float $x, float $y, float $w, float $h, string $c, float $rx = 4): string
    => sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="%s" class="%s"/>', $x, $y, $w, $h, $rx, $c);

/** A line of "text": a thin rounded bar. */
$bar = static fn (float $x, float $y, float $w, string $c = 'ha-line'): string
    => sprintf('<rect x="%s" y="%s" width="%s" height="5" rx="2.5" class="%s"/>', $x, $y, $w, $c);

/** A circle. */
$dot = static fn (float $cx, float $cy, float $rad, string $c): string
    => sprintf('<circle cx="%s" cy="%s" r="%s" class="%s"/>', $cx, $cy, $rad, $c);

/** A stroked path. */
$path = static fn (string $d, string $c = 'ha-stroke'): string => sprintf('<path d="%s" class="%s"/>', $d, $c);

/** A key-shaped glyph at (x, y). */
$key = static fn (float $x, float $y, string $c = 'ha-stroke-accent'): string => sprintf(
    '<g transform="translate(%s %s)"><circle cx="6" cy="6" r="5" class="%s"/><path d="M11 6h11M18 6v4M22 6v3" class="%s"/></g>', $x, $y, $c, $c
);

/** A content card: thumbnail, two lines of text, and a coloured status chip. */
$card = static function (float $x, float $y, float $w, string $chip = 'ha-soft') use ($r, $bar): string {
    return $r($x, $y, $w, 34, 'ha-panel', 6)
        . $r($x + 6, $y + 6, 30, 22, 'ha-bg', 3)
        . $bar($x + 42, $y + 9, $w * 0.45, 'ha-text')
        . $bar($x + 42, $y + 20, $w * 0.3)
        . $r($x + $w - 30, $y + 12, 22, 10, $chip, 5);
};

$arts = [
    'dashboard.today' =>
        $card(20, 18, 200, 'ha-soft')
        . $r(176, 28, 36, 14, 'ha-twitch', 7) . $path('M188 35l6-4v8z', 'ha-white-fill')
        . $r(26, 60, 52, 12, 'ha-soft', 6) . $dot(34, 66, 2.5, 'ha-accent') . $bar(40, 63.5, 30, 'ha-accent')
        . $card(20, 82, 200, 'ha-okbg')
        . $dot(30, 128, 4, 'ha-ok') . $bar(40, 125.5, 70, 'ha-text'),

    'dashboard.pick' =>
        $r(14, 20, 90, 110, 'ha-bg', 8)
        . $r(22, 30, 74, 22, 'ha-panel', 5) . $bar(28, 38, 50, 'ha-text')
        . $r(22, 58, 74, 22, 'ha-panel', 5) . $bar(28, 66, 40, 'ha-text')
        . $r(22, 86, 74, 22, 'ha-soft', 5) . $bar(28, 94, 56, 'ha-accent')
        . $path('M104 97c30 0 40-30 60-30', 'ha-stroke-accent-dash') . $path('M158 61l7 6-8 5', 'ha-stroke-accent')
        . $r(150, 40, 76, 60, 'ha-panel', 8) . $dot(188, 62, 12, 'ha-soft') . $path('M188 56v6l4 3', 'ha-stroke-accent')
        . $bar(162, 84, 52, 'ha-text'),

    'dashboard.shortcuts' =>
        implode('', array_map(static fn (int $i): string => sprintf(
            '<rect x="%d" y="45" width="36" height="46" rx="8" class="ha-panel"/><circle cx="%d" cy="62" r="8" class="%s"/><rect x="%d" y="78" width="22" height="5" rx="2.5" class="ha-line"/>',
            14 + $i * 43, 32 + $i * 43, $i === 0 ? 'ha-accent' : 'ha-soft', 21 + $i * 43
        ), range(0, 4)))
        . $path('M32 62h0M28 62h8M32 58v8', 'ha-stroke-white'),

    'dashboard.attention' =>
        $r(20, 16, 200, 118, 'ha-panel', 8)
        . $path('M36 34l7 12H29z', 'ha-warn-fill') . $bar(52, 37, 110, 'ha-text') . $r(178, 33, 30, 11, 'ha-warnbg', 5)
        . $path('M36 62l7 12H29z', 'ha-warn-fill') . $bar(52, 65, 90, 'ha-text') . $r(178, 61, 30, 11, 'ha-warnbg', 5)
        . $dot(36, 98, 6, 'ha-soft') . $key(31, 92, 'ha-stroke-accent') . $bar(52, 95, 120, 'ha-text') . $r(178, 91, 30, 11, 'ha-soft', 5)
        . $bar(52, 112, 70),

    'dashboard.week' =>
        implode('', array_map(static function (int $i): string {
            $busy = in_array($i, [0, 2, 3, 5], true);
            return sprintf('<rect x="%d" y="40" width="26" height="70" rx="6" class="%s"/><rect x="%d" y="48" width="14" height="5" rx="2.5" class="ha-line"/>', 15 + $i * 31, $i === 0 ? 'ha-soft' : 'ha-panel', 21 + $i * 31)
                . ($busy ? sprintf('<rect x="%d" y="64" width="18" height="10" rx="3" class="ha-accent"/>', 19 + $i * 31) : '')
                . ($i === 3 ? sprintf('<rect x="%d" y="80" width="18" height="10" rx="3" class="ha-ok"/>', 19 + $i * 31) : '');
        }, range(0, 6))),

    'dashboard.schedule' =>
        $r(30, 18, 180, 114, 'ha-panel', 8)
        . implode('', array_map(static fn (int $i): string => sprintf(
            '<rect x="44" y="%d" width="22" height="12" rx="6" class="%s"/><circle cx="%d" cy="%d" r="4" class="ha-white-fill"/><rect x="76" y="%d" width="34" height="6" rx="3" class="ha-line"/><rect x="122" y="%d" width="70" height="10" rx="3" class="%s"/>',
            30 + $i * 20, in_array($i, [0, 1, 3], true) ? 'ha-accent' : 'ha-line',
            in_array($i, [0, 1, 3], true) ? 60 : 50, 36 + $i * 20, 33 + $i * 20, 31 + $i * 20, in_array($i, [0, 1, 3], true) ? 'ha-soft' : 'ha-bg'
        ), range(0, 4))),

    'keys.vault' =>
        implode('', array_map(static function (int $i) use ($r, $bar, $key): string {
            $x = 16 + ($i % 3) * 72;
            $y = 22 + intdiv($i, 3) * 56;
            return $r($x, $y, 64, 48, 'ha-panel', 7) . $key($x + 8, $y + 9) . $bar($x + 8, $y + 26, 44, 'ha-text') . $bar($x + 8, $y + 36, 28);
        }, range(0, 5)))
        . $r(196, 98, 34, 42, 'ha-accent', 8) . $path('M205 116v-5a8 8 0 0 1 16 0v5', 'ha-stroke-white') . $r(203, 116, 20, 16, 'ha-white-fill', 3),

    'keys.add' =>
        $r(14, 22, 96, 106, 'ha-panel', 8)
        . implode('', array_map(static fn (int $i): string => sprintf('<rect x="24" y="%d" width="%d" height="6" rx="3" class="ha-text"/>', 34 + $i * 14, [70, 62, 74, 58, 66, 70][$i]), range(0, 5)))
        . $path('M118 75h22', 'ha-stroke-accent') . $path('M134 69l7 6-7 6', 'ha-stroke-accent')
        . $r(156, 40, 70, 22, 'ha-panel', 5) . $key(162, 45)
        . $r(150, 64, 70, 22, 'ha-panel', 5) . $key(156, 69)
        . $r(162, 88, 70, 22, 'ha-panel', 5) . $key(168, 93),

    'keys.hidden' =>
        $r(18, 40, 204, 40, 'ha-panel', 8)
        . implode('', array_map(static fn (int $i): string => sprintf('<circle cx="%d" cy="60" r="4" class="ha-text"/>', 36 + $i * 13), range(0, 9)))
        . $r(170, 50, 42, 20, 'ha-soft', 10) . $path('M178 60s4-6 9-6 9 6 9 6-4 6-9 6-9-6-9-6z', 'ha-stroke-accent')
        . $r(18, 94, 120, 32, 'ha-soft', 8) . $bar(28, 107, 96, 'ha-accent')
        . $r(146, 94, 76, 32, 'ha-panel', 8) . $r(160, 103, 12, 14, 'ha-line', 2) . $r(165, 99, 12, 14, 'ha-text', 2) . $bar(184, 107, 28),

    'keys.status' =>
        $r(10, 34, 62, 22, 'ha-okbg', 11) . $bar(20, 42, 42, 'ha-ok')
        . $path('M74 45h12', 'ha-stroke') . $path('M82 41l5 4-5 4', 'ha-stroke')
        . $r(90, 34, 62, 22, 'ha-soft', 11) . $bar(100, 42, 42, 'ha-accent')
        . $path('M154 45h12', 'ha-stroke') . $path('M162 41l5 4-5 4', 'ha-stroke')
        . $r(170, 34, 62, 22, 'ha-bg', 11) . $bar(180, 42, 42, 'ha-text')
        . $path('M41 58c0 30 20 40 50 40', 'ha-stroke-dash')
        . $r(90, 88, 62, 22, 'ha-warnbg', 11) . $bar(100, 96, 42, 'ha-warn')
        . $path('M154 99h12', 'ha-stroke') . $path('M162 95l5 4-5 4', 'ha-stroke')
        . $r(170, 88, 62, 22, 'ha-twitchbg', 11) . $bar(180, 96, 42, 'ha-twitch'),

    'keys.dates' =>
        $r(22, 20, 120, 110, 'ha-panel', 8) . $r(22, 20, 120, 24, 'ha-soft', 8) . $r(22, 36, 120, 8, 'ha-soft', 0)
        . implode('', array_map(static fn (int $i): string => sprintf('<rect x="%d" y="%d" width="14" height="12" rx="3" class="%s"/>', 32 + ($i % 6) * 18, 54 + intdiv($i, 6) * 20, $i === 15 ? 'ha-danger' : ($i === 8 ? 'ha-warn' : 'ha-bg')), range(0, 17)))
        . $r(156, 34, 70, 34, 'ha-warnbg', 8) . $path('M170 44l6 10h-12z', 'ha-warn-fill') . $bar(184, 47, 34, 'ha-warn')
        . $r(156, 80, 70, 34, 'ha-dangerbg', 8) . $dot(171, 97, 7, 'ha-danger-ring') . $path('M171 93v4l3 2', 'ha-stroke-danger') . $bar(184, 94, 34, 'ha-danger'),

    'keys.content' =>
        $r(16, 46, 80, 50, 'ha-panel', 8) . $key(26, 56) . $bar(26, 76, 56, 'ha-text')
        . $path('M98 71c20 0 24-20 46-20', 'ha-stroke-accent-dash') . $dot(98, 71, 3, 'ha-accent') . $dot(144, 51, 3, 'ha-accent')
        . $card(144, 34, 84, 'ha-okbg')
        . $r(144, 82, 84, 30, 'ha-panel', 6) . $bar(152, 92, 44, 'ha-text') . $r(198, 89, 24, 12, 'ha-warnbg', 6),

    'keys.giveaways' =>
        $r(80, 58, 80, 62, 'ha-accent', 6) . $r(72, 44, 96, 20, 'ha-accent-dark', 5)
        . $r(114, 44, 12, 76, 'ha-white-fill', 0)
        . $path('M120 44c-8-18-30-18-26-4 2 6 18 4 26 4zM120 44c8-18 30-18 26-4-2 6-18 4-26 4z', 'ha-stroke-accent')
        . $key(28, 74) . $path('M56 80h16', 'ha-stroke-accent-dash')
        . $dot(196, 70, 14, 'ha-soft') . $path('M190 70l4 4 8-9', 'ha-stroke-accent') . $bar(176, 92, 40, 'ha-text'),

    'content.what' =>
        $r(26, 20, 188, 110, 'ha-panel', 10) . $r(38, 32, 56, 40, 'ha-bg', 6) . $path('M50 62l10-12 8 9 6-6 10 9', 'ha-stroke')
        . $bar(104, 36, 92, 'ha-text') . $bar(104, 48, 70)
        . $r(104, 60, 40, 12, 'ha-twitchbg', 6) . $r(150, 60, 46, 12, 'ha-soft', 6)
        . $dot(46, 94, 5, 'ha-accent') . $bar(56, 91.5, 60) . $dot(46, 112, 5, 'ha-warn') . $bar(56, 109.5, 80)
        . $r(150, 98, 50, 18, 'ha-okbg', 9) . $bar(160, 104.5, 30, 'ha-ok'),

    'content.calendar' =>
        $r(12, 22, 58, 110, 'ha-bg', 8) . $r(18, 30, 46, 18, 'ha-panel', 4) . $r(18, 52, 46, 18, 'ha-soft', 4) . $r(18, 74, 46, 18, 'ha-panel', 4)
        . $r(80, 22, 148, 110, 'ha-panel', 8)
        . implode('', array_map(static fn (int $i): string => sprintf('<rect x="%d" y="%d" width="16" height="22" rx="3" class="%s"/>', 88 + ($i % 7) * 20, 32 + intdiv($i, 7) * 30, $i === 10 ? 'ha-soft' : 'ha-bg'), range(0, 20)))
        . $r(148, 66, 16, 8, 'ha-accent', 2)
        . $path('M64 61c40 0 50 0 82 8', 'ha-stroke-accent-dash') . $path('M140 64l7 5-8 4', 'ha-stroke-accent'),

    'content.resize' =>
        $r(30, 16, 180, 118, 'ha-panel', 8)
        . implode('', array_map(static fn (int $i): string => sprintf('<rect x="40" y="%d" width="160" height="1" class="ha-line"/><rect x="40" y="%d" width="18" height="5" rx="2.5" class="ha-line"/>', 30 + $i * 22, 23 + $i * 22), range(0, 4)))
        . $r(70, 34, 110, 58, 'ha-soft', 6) . $r(70, 34, 4, 58, 'ha-accent', 2) . $bar(82, 44, 70, 'ha-accent') . $bar(82, 55, 44)
        . $r(110, 86, 30, 6, 'ha-accent', 3)
        . $path('M125 98v26', 'ha-stroke-accent') . $path('M119 118l6 7 6-7', 'ha-stroke-accent'),

    'content.statuses' =>
        $r(14, 60, 60, 26, 'ha-soft', 13) . $bar(26, 70, 36, 'ha-accent')
        . $path('M76 73h14', 'ha-stroke') . $path('M86 69l5 4-5 4', 'ha-stroke')
        . $r(94, 60, 60, 26, 'ha-dangerbg', 13) . $dot(108, 73, 4, 'ha-danger') . $bar(116, 70, 28, 'ha-danger')
        . $path('M156 73h14', 'ha-stroke') . $path('M166 69l5 4-5 4', 'ha-stroke')
        . $r(174, 60, 56, 26, 'ha-okbg', 13) . $path('M186 73l4 4 7-8', 'ha-stroke-ok') . $bar(200, 70, 22, 'ha-ok')
        . $path('M44 88c0 24 10 30 30 30', 'ha-stroke-dash')
        . $r(76, 106, 60, 22, 'ha-bg', 11) . $bar(88, 114.5, 36, 'ha-text'),

    'content.title' =>
        $r(14, 40, 212, 46, 'ha-panel', 8)
        . $r(24, 52, 46, 22, 'ha-soft', 6) . $bar(30, 60.5, 34, 'ha-accent')
        . $bar(78, 60.5, 60, 'ha-text')
        . $r(146, 52, 36, 22, 'ha-okbg', 6) . $bar(152, 60.5, 24, 'ha-ok')
        . $r(186, 52, 32, 22, 'ha-twitchbg', 6) . $bar(191, 60.5, 22, 'ha-twitch')
        . $r(30, 100, 54, 22, 'ha-bg', 11) . $r(92, 100, 60, 22, 'ha-bg', 11) . $r(160, 100, 50, 22, 'ha-bg', 11)
        . $bar(40, 108.5, 34) . $bar(102, 108.5, 40) . $bar(170, 108.5, 30)
        . $path('M57 98v-10M122 98v-10M185 98v-10', 'ha-stroke-dash'),

    'content.warnings' =>
        $card(20, 20, 200, 'ha-soft')
        . $r(20, 64, 94, 22, 'ha-warnbg', 11) . $path('M32 69l5 9h-10z', 'ha-warn-fill') . $bar(44, 72.5, 60, 'ha-warn')
        . $r(122, 64, 98, 22, 'ha-dangerbg', 11) . $dot(134, 75, 5, 'ha-danger-ring') . $bar(146, 72.5, 64, 'ha-danger')
        . $r(20, 94, 110, 22, 'ha-bg', 11) . $key(30, 99, 'ha-stroke') . $bar(60, 102.5, 60, 'ha-text')
        . $r(138, 94, 82, 22, 'ha-warnbg', 11) . $dot(150, 105, 5, 'ha-warn') . $bar(162, 102.5, 48, 'ha-warn'),

    'content.twitch' =>
        $card(10, 52, 96, 'ha-soft')
        . $path('M108 69h24', 'ha-stroke-twitch') . $path('M126 63l7 6-7 6', 'ha-stroke-twitch')
        . $r(138, 24, 92, 102, 'ha-panel', 8) . $r(138, 24, 92, 22, 'ha-twitch', 8) . $r(138, 38, 92, 8, 'ha-twitch', 0)
        . $path('M150 31h8v7h-3l-2 2v-2h-3z', 'ha-white-fill')
        . $bar(148, 56, 70, 'ha-text') . $r(148, 68, 46, 12, 'ha-twitchbg', 6) . $r(148, 86, 30, 10, 'ha-soft', 5) . $r(182, 86, 30, 10, 'ha-soft', 5)
        . $dot(156, 110, 4, 'ha-danger') . $bar(164, 107.5, 40, 'ha-danger'),
];
?>
<svg viewBox="0 0 240 150" class="ha" role="presentation"><?= $arts[$art] ?? '' ?></svg>
