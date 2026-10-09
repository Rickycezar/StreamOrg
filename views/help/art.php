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

    'defaults.schedule' =>
        $r(30, 18, 180, 114, 'ha-panel', 8)
        . implode('', array_map(static fn (int $i): string => sprintf(
            '<rect x="44" y="%d" width="12" height="12" rx="3" class="%s"/><rect x="64" y="%d" width="40" height="6" rx="3" class="ha-line"/><rect x="116" y="%d" width="36" height="12" rx="4" class="%s"/><rect x="158" y="%d" width="36" height="12" rx="4" class="ha-bg"/>',
            30 + $i * 20, $i === 3 ? 'ha-line' : 'ha-accent', 33 + $i * 20, 30 + $i * 20, $i === 3 ? 'ha-bg' : 'ha-soft', 30 + $i * 20
        ), range(0, 4))),

    'defaults.length' =>
        $r(26, 26, 188, 98, 'ha-panel', 8)
        . implode('', array_map(static fn (int $i): string => sprintf('<rect x="40" y="%d" width="160" height="1" class="ha-line"/>', 40 + $i * 20), range(0, 4)))
        . $r(60, 44, 120, 52, 'ha-soft', 6) . $r(60, 44, 4, 52, 'ha-accent', 2) . $bar(72, 54, 70, 'ha-accent')
        . $dot(196, 70, 14, 'ha-bg') . $path('M196 62v8l5 3', 'ha-stroke-accent')
        . $path('M120 100v14', 'ha-stroke-accent') . $path('M114 108l6 7 6-7', 'ha-stroke-accent'),

    'defaults.prefixes' =>
        $r(14, 30, 212, 34, 'ha-panel', 8)
        . $r(22, 38, 54, 18, 'ha-accent', 9) . $bar(30, 44.5, 38, 'ha-white-fill')
        . $r(80, 38, 40, 18, 'ha-accent', 9) . $bar(88, 44.5, 24, 'ha-white-fill')
        . $bar(128, 44.5, 80, 'ha-text')
        . $r(22, 82, 62, 20, 'ha-bg', 10) . $bar(32, 89.5, 42)
        . $r(90, 82, 50, 20, 'ha-bg', 10) . $bar(100, 89.5, 30)
        . $r(146, 82, 72, 20, 'ha-bg', 10) . $bar(156, 89.5, 52)
        . $path('M49 80v-14M100 80v-14', 'ha-stroke-accent-dash'),

    'defaults.counters' =>
        $r(26, 32, 188, 40, 'ha-panel', 8)
        . $bar(40, 49.5, 30, 'ha-text') . $r(76, 42, 60, 20, 'ha-soft', 6) . $bar(84, 49.5, 44, 'ha-accent')
        . $r(150, 40, 54, 24, 'ha-bg', 6) . $bar(160, 49.5, 34, 'ha-text')
        . $r(60, 92, 120, 34, 'ha-accent', 10) . $bar(76, 106.5, 50, 'ha-white-fill') . $r(132, 100, 36, 18, 'ha-white-fill', 6) . $bar(140, 106.5, 20, 'ha-accent')
        . $path('M177 72c0 12-10 14-20 20', 'ha-stroke-accent-dash'),

    'defaults.numbers' =>
        $r(16, 40, 64, 30, 'ha-panel', 6) . $bar(26, 52.5, 44, 'ha-text')
        . $r(88, 40, 64, 30, 'ha-panel', 6) . $bar(98, 52.5, 44, 'ha-text')
        . $r(160, 40, 64, 30, 'ha-soft', 6) . $bar(170, 52.5, 44, 'ha-accent')
        . $dot(48, 32, 9, 'ha-bg') . $dot(120, 32, 9, 'ha-bg') . $dot(192, 32, 9, 'ha-accent')
        . $path('M192 76v20', 'ha-stroke-danger') . $path('M186 90l6 7 6-7', 'ha-stroke-danger')
        . $r(160, 100, 64, 30, 'ha-dangerbg', 6) . $path('M184 108l16 14M200 108l-16 14', 'ha-stroke-danger')
        . $path('M160 115c-30 0-40-20-40-30', 'ha-stroke-accent-dash'),

    'defaults.limit' =>
        $r(14, 44, 212, 40, 'ha-panel', 8)
        . $r(24, 55, 50, 18, 'ha-accent', 9) . $bar(84, 61.5, 110, 'ha-text')
        . $r(14, 96, 160, 8, 'ha-bg', 4) . $r(14, 96, 132, 8, 'ha-ok', 4)
        . $bar(182, 97.5, 44, 'ha-text')
        . $r(14, 116, 160, 8, 'ha-bg', 4) . $r(14, 116, 160, 8, 'ha-danger', 4) . $bar(182, 117.5, 44, 'ha-danger'),

    'giveaways.setup' =>
        $r(26, 20, 188, 110, 'ha-panel', 10)
        . $r(40, 34, 30, 30, 'ha-accent', 8) . $path('M47 49h16M55 41v16', 'ha-stroke-white')
        . $bar(80, 38, 100, 'ha-text') . $bar(80, 52, 60)
        . $r(40, 76, 70, 18, 'ha-soft', 9) . $bar(48, 82.5, 54, 'ha-accent')
        . $r(116, 76, 84, 18, 'ha-twitchbg', 9) . $bar(124, 82.5, 68, 'ha-twitch')
        . $r(40, 102, 160, 16, 'ha-bg', 4) . $bar(48, 107.5, 120),

    'giveaways.prizes' =>
        $r(14, 30, 92, 92, 'ha-bg', 8)
        . $r(22, 38, 76, 22, 'ha-panel', 5) . $key(28, 43)
        . $r(22, 64, 76, 22, 'ha-warnbg', 5) . $key(28, 69, 'ha-stroke')
        . $r(22, 90, 76, 22, 'ha-panel', 5) . $key(28, 95)
        . $path('M108 75h24', 'ha-stroke-accent') . $path('M126 69l7 6-7 6', 'ha-stroke-accent')
        . $r(150, 56, 76, 60, 'ha-accent', 6) . $r(142, 44, 92, 18, 'ha-accent-dark', 5) . $r(182, 44, 12, 72, 'ha-white-fill', 0),

    'giveaways.winners' =>
        $r(22, 26, 196, 98, 'ha-panel', 10)
        . $dot(44, 50, 10, 'ha-twitchbg') . $bar(62, 44, 80, 'ha-text') . $bar(62, 54, 50) . $r(170, 42, 36, 16, 'ha-okbg', 8) . $path('M182 50l4 4 7-7', 'ha-stroke-ok')
        . $dot(44, 86, 10, 'ha-twitchbg') . $bar(62, 80, 70, 'ha-text') . $bar(62, 90, 40) . $r(170, 78, 36, 16, 'ha-soft', 8) . $bar(176, 83.5, 24, 'ha-accent')
        . $r(36, 106, 70, 12, 'ha-bg', 6),

    'giveaways.claim' =>
        $r(14, 36, 96, 60, 'ha-panel', 8) . $r(24, 46, 76, 14, 'ha-soft', 7) . $path('M32 53h0', 'ha-stroke-accent') . $bar(40, 50.5, 52, 'ha-accent')
        . $r(24, 68, 50, 16, 'ha-twitch', 8) . $bar(32, 73.5, 34, 'ha-white-fill')
        . $path('M112 66h26', 'ha-stroke-accent') . $path('M132 60l7 6-7 6', 'ha-stroke-accent')
        . $r(146, 24, 80, 104, 'ha-panel', 10) . $dot(186, 52, 14, 'ha-okbg') . $path('M180 52l4 4 8-9', 'ha-stroke-ok')
        . $r(158, 76, 56, 14, 'ha-bg', 4) . $bar(164, 80.5, 44, 'ha-text') . $r(164, 98, 44, 14, 'ha-accent', 7),

    'giveaways.private' =>
        $r(18, 44, 70, 70, 'ha-accent', 10) . $path('M38 70v-8a15 15 0 0 1 30 0v8', 'ha-stroke-white') . $r(34, 70, 38, 30, 'ha-white-fill', 4) . $dot(53, 85, 3, 'ha-accent')
        . $path('M92 64c20-20 40-20 60 0', 'ha-stroke-accent-dash') . $path('M146 58l6 6-8 3', 'ha-stroke-accent')
        . $path('M152 100c-20 20-40 20-60 0', 'ha-stroke-dash') . $path('M98 106l-6-6 8-3', 'ha-stroke')
        . $r(156, 52, 66, 56, 'ha-panel', 10) . $key(164, 62) . $key(164, 80) . $bar(196, 64, 18) . $bar(196, 82, 18),

    'giveaways.finish' =>
        $r(14, 60, 60, 24, 'ha-soft', 12) . $bar(26, 69.5, 36, 'ha-accent')
        . $path('M76 72h12', 'ha-stroke') . $path('M84 68l5 4-5 4', 'ha-stroke')
        . $r(92, 60, 60, 24, 'ha-bg', 12) . $bar(104, 69.5, 36, 'ha-text')
        . $path('M154 72h12', 'ha-stroke') . $path('M162 68l5 4-5 4', 'ha-stroke')
        . $r(170, 60, 58, 24, 'ha-okbg', 12) . $path('M182 72l4 4 7-8', 'ha-stroke-ok') . $bar(196, 69.5, 24, 'ha-ok')
        . $key(186, 100) . $path('M199 98c0-8-6-12-14-12', 'ha-stroke-accent-dash'),

    'embargoes.what' =>
        $r(20, 24, 120, 104, 'ha-panel', 8) . $r(20, 24, 120, 22, 'ha-soft', 8) . $r(20, 38, 120, 8, 'ha-soft', 0)
        . implode('', array_map(static fn (int $i): string => sprintf('<rect x="%d" y="%d" width="14" height="12" rx="3" class="%s"/>', 30 + ($i % 6) * 18, 56 + intdiv($i, 6) * 20, $i < 9 ? 'ha-dangerbg' : ($i === 9 ? 'ha-ok' : 'ha-bg')), range(0, 17)))
        . $r(156, 46, 64, 60, 'ha-dangerbg', 10) . $path('M178 64h20v22h-20zM183 64v-5a5 5 0 0 1 10 0v5', 'ha-stroke-danger'),

    'embargoes.kinds' =>
        implode('', array_map(static fn (int $i): string => sprintf(
            '<rect x="%d" y="%d" width="96" height="24" rx="12" class="%s"/><rect x="%d" y="%d" width="60" height="5" rx="2.5" class="%s"/>',
            20 + ($i % 2) * 104, 24 + intdiv($i, 2) * 34, ['ha-soft', 'ha-okbg', 'ha-warnbg', 'ha-twitchbg', 'ha-bg'][$i], 38 + ($i % 2) * 104, 33.5 + intdiv($i, 2) * 34, ['ha-accent', 'ha-ok', 'ha-warn', 'ha-twitch', 'ha-text'][$i]
        ), range(0, 4))),

    'embargoes.several' =>
        $r(20, 30, 200, 92, 'ha-panel', 10) . $r(32, 42, 40, 30, 'ha-bg', 5)
        . $bar(82, 46, 90, 'ha-text')
        . $r(32, 84, 56, 16, 'ha-soft', 8) . $r(94, 84, 56, 16, 'ha-warnbg', 8) . $r(156, 84, 52, 16, 'ha-twitchbg', 8)
        . $bar(40, 89.5, 40, 'ha-accent') . $bar(102, 89.5, 40, 'ha-warn') . $bar(164, 89.5, 36, 'ha-twitch'),

    'embargoes.release' =>
        $r(18, 40, 90, 70, 'ha-panel', 8) . $r(26, 48, 74, 26, 'ha-bg', 5) . $bar(26, 82, 60, 'ha-text') . $bar(26, 94, 40)
        . $path('M110 75h22', 'ha-stroke-accent') . $path('M126 69l7 6-7 6', 'ha-stroke-accent')
        . $r(140, 46, 84, 58, 'ha-soft', 10) . $r(152, 58, 22, 20, 'ha-panel', 4) . $bar(180, 62, 34, 'ha-accent') . $bar(152, 88, 60, 'ha-accent'),

    'embargoes.warnings' =>
        $card(20, 24, 200, 'ha-soft')
        . $r(20, 70, 200, 24, 'ha-warnbg', 12) . $path('M34 76l6 11H28z', 'ha-warn-fill') . $bar(48, 79.5, 140, 'ha-warn')
        . $r(20, 102, 200, 24, 'ha-dangerbg', 12) . $r(30, 108, 12, 12, 'ha-danger', 6) . $bar(48, 111.5, 120, 'ha-danger'),

    'embargoes.uncovered' =>
        $r(20, 24, 200, 104, 'ha-panel', 10)
        . implode('', array_map(static fn (int $i): string => $key(34, 38 + $i * 26, $i === 1 ? 'ha-stroke' : 'ha-stroke-accent')
            . sprintf('<rect x="66" y="%d" width="%d" height="5" rx="2.5" class="ha-text"/>', 44 + $i * 26, [90, 70, 100, 80][$i])
            . sprintf('<rect x="172" y="%d" width="36" height="14" rx="7" class="%s"/>', 39 + $i * 26, $i === 1 ? 'ha-bg' : 'ha-warnbg'), range(0, 3))),

    'collabs.streamers' =>
        implode('', array_map(static fn (int $i): string => sprintf(
            '<rect x="%d" y="30" width="62" height="90" rx="10" class="ha-panel"/><circle cx="%d" cy="58" r="15" class="%s"/><rect x="%d" y="82" width="42" height="5" rx="2.5" class="ha-text"/><rect x="%d" y="94" width="30" height="10" rx="5" class="ha-twitchbg"/>',
            16 + $i * 72, 47 + $i * 72, ['ha-soft', 'ha-twitchbg', 'ha-okbg'][$i], 26 + $i * 72, 32 + $i * 72
        ), range(0, 2))),

    'collabs.idea' =>
        $r(12, 34, 62, 24, 'ha-bg', 12) . $dot(26, 46, 4, 'ha-warn') . $bar(36, 43.5, 30, 'ha-text')
        . $path('M76 46h10', 'ha-stroke') . $path('M83 42l4 4-4 4', 'ha-stroke')
        . $r(90, 34, 62, 24, 'ha-soft', 12) . $bar(102, 43.5, 38, 'ha-accent')
        . $path('M154 46h10', 'ha-stroke') . $path('M161 42l4 4-4 4', 'ha-stroke')
        . $r(168, 34, 62, 24, 'ha-soft', 12) . $bar(180, 43.5, 38, 'ha-accent')
        . $path('M199 60c0 14-10 22-30 26', 'ha-stroke-dash')
        . $r(90, 76, 76, 26, 'ha-twitchbg', 13) . $bar(102, 86.5, 52, 'ha-twitch')
        . $path('M168 89h10', 'ha-stroke') . $path('M175 85l4 4-4 4', 'ha-stroke')
        . $r(182, 76, 48, 26, 'ha-okbg', 13) . $path('M193 89l4 4 7-8', 'ha-stroke-ok') . $bar(208, 86.5, 14, 'ha-ok')
        . $r(12, 112, 62, 22, 'ha-dangerbg', 11) . $path('M24 118l8 10M32 118l-8 10', 'ha-stroke-danger') . $bar(40, 120.5, 26, 'ha-danger'),

    'collabs.cast' =>
        $r(20, 20, 200, 110, 'ha-panel', 10)
        . implode('', array_map(static fn (int $i): string => sprintf(
            '<rect x="32" y="%d" width="12" height="12" rx="3" class="%s"/><circle cx="62" cy="%d" r="9" class="ha-twitchbg"/><rect x="78" y="%d" width="56" height="5" rx="2.5" class="ha-text"/><rect x="148" y="%d" width="58" height="16" rx="8" class="%s"/>',
            32 + $i * 32, $i === 2 ? 'ha-line' : 'ha-accent', 38 + $i * 32, 35.5 + $i * 32, 30 + $i * 32, ['ha-soft', 'ha-okbg', 'ha-bg'][$i]
        ), range(0, 2))),

    'collabs.plan' =>
        $r(14, 34, 92, 80, 'ha-panel', 10) . $dot(38, 58, 10, 'ha-twitchbg') . $dot(56, 58, 10, 'ha-soft') . $dot(74, 58, 10, 'ha-okbg')
        . $bar(26, 80, 64, 'ha-text') . $r(26, 92, 50, 12, 'ha-soft', 6)
        . $path('M108 74h24', 'ha-stroke-accent') . $path('M126 68l7 6-7 6', 'ha-stroke-accent')
        . $card(138, 40, 92, 'ha-soft')
        . $r(138, 82, 92, 22, 'ha-bg', 11) . $bar(148, 90.5, 30) . $r(184, 87, 38, 12, 'ha-twitchbg', 6),

    'collabs.together' =>
        $r(12, 30, 92, 90, 'ha-panel', 10) . $dot(32, 50, 9, 'ha-twitchbg') . $bar(46, 47.5, 46, 'ha-text')
        . $r(22, 70, 72, 18, 'ha-soft', 6) . $bar(30, 76.5, 40) . $r(22, 94, 50, 10, 'ha-bg', 5)
        . $r(136, 30, 92, 90, 'ha-panel', 10) . $dot(156, 50, 9, 'ha-okbg') . $bar(170, 47.5, 46, 'ha-text')
        . $r(146, 70, 72, 18, 'ha-soft', 6) . $bar(154, 76.5, 40) . $r(146, 94, 40, 10, 'ha-bg', 5)
        . $path('M98 79h44', 'ha-stroke-accent') . $dot(120, 79, 11, 'ha-okbg') . $path('M114.5 79l4 4 7-8', 'ha-stroke-ok'),

    'together.invite' =>
        $r(12, 30, 112, 90, 'ha-panel', 10) . $bar(24, 44, 60, 'ha-text')
        . $dot(32, 66, 9, 'ha-twitchbg') . $dot(50, 66, 9, 'ha-okbg') . $dot(68, 66, 9, 'ha-soft')
        . $r(24, 90, 88, 18, 'ha-accent', 9) . $bar(38, 96.5, 60, 'ha-white-fill')
        . $path('M128 99c22 0 26-38 50-38', 'ha-stroke-accent-dash')
        . $path('M190 40a14 14 0 0 1 14 14c0 14 6 18 6 18h-40s6-4 6-18a14 14 0 0 1 14-14zM186 78a5 5 0 0 0 8 0', 'ha-stroke')
        . $dot(204, 42, 7, 'ha-accent') . $r(162, 96, 64, 22, 'ha-bg', 11) . $dot(174, 107, 6, 'ha-okbg') . $bar(184, 104.5, 34, 'ha-text'),

    'together.join' =>
        $r(16, 22, 208, 106, 'ha-panel', 10) . $r(16, 22, 208, 106, 'ha-soft', 10)
        . $dot(40, 50, 11, 'ha-twitchbg') . $bar(58, 44, 110, 'ha-text') . $bar(58, 55, 80)
        . $bar(32, 76, 170) . $bar(32, 86, 130)
        . $r(32, 100, 70, 18, 'ha-accent', 6) . $bar(44, 106.5, 46, 'ha-white-fill')
        . $r(110, 100, 54, 18, 'ha-panel', 6) . $bar(122, 106.5, 30, 'ha-text')
        . $dot(204, 46, 10, 'ha-okbg') . $path('M199 46l4 4 6-7', 'ha-stroke-ok'),

    'together.own' =>
        $card(10, 36, 104, 'ha-twitchbg') . $bar(52, 76, 50, 'ha-text')
        . $card(126, 36, 104, 'ha-okbg') . $bar(168, 76, 34, 'ha-text')
        . $path('M62 86v10h40M178 86v10h-40', 'ha-stroke-dash')
        . $path('M104 90h12a6 6 0 0 1 0 12h-12a6 6 0 0 1 0-12z', 'ha-stroke-accent') . $path('M124 90h12a6 6 0 0 1 0 12h-12a6 6 0 0 1 0-12z', 'ha-stroke-accent')
        . $r(52, 120, 136, 16, 'ha-bg', 8) . $dot(64, 128, 3, 'ha-accent') . $bar(72, 125.5, 100, 'ha-text'),

    'together.time' =>
        $r(14, 18, 212, 116, 'ha-panel', 8)
        . implode('', array_map(static fn (int $i): string => sprintf('<rect x="%d" y="30" width="1" height="96" class="ha-line"/>', 44 + $i * 30), range(0, 5)))
        . $r(48, 46, 24, 30, 'ha-soft', 4) . $r(48, 46, 3, 30, 'ha-accent', 1.5)
        . $r(138, 78, 24, 30, 'ha-bg', 4) . $path('M138 78h24v30h-24z', 'ha-stroke-accent-dash')
        . $path('M74 62c30 0 40 20 60 26', 'ha-stroke-accent-dash') . $path('M128 84l7 4-8 4', 'ha-stroke-accent')
        . $r(168, 40, 50, 24, 'ha-warnbg', 12) . $bar(178, 49.5, 30, 'ha-warn'),

    'together.agree' =>
        $r(16, 18, 208, 116, 'ha-warnbg', 10) . $bar(30, 32, 90, 'ha-text') . $bar(30, 44, 60, 'ha-warn')
        . $dot(38, 66, 6, 'ha-okbg') . $path('M35 66l2.5 2.5 4-4.5', 'ha-stroke-ok') . $bar(50, 63.5, 80, 'ha-text')
        . $dot(38, 84, 6, 'ha-panel') . $bar(50, 81.5, 64, 'ha-text')
        . $r(30, 102, 64, 20, 'ha-accent', 6) . $bar(42, 109.5, 40, 'ha-white-fill')
        . $r(102, 102, 56, 20, 'ha-panel', 6) . $bar(114, 109.5, 32, 'ha-text')
        . $dot(196, 72, 18, 'ha-okbg') . $path('M187 72l6 6 11-12', 'ha-stroke-ok'),

    'together.leave' =>
        $card(10, 50, 100, 'ha-twitchbg') . $card(130, 50, 100, 'ha-okbg')
        . $path('M106 67h8M126 67h8', 'ha-stroke') . $path('M114 60l4 7-4 7M126 60l-4 7 4 7', 'ha-stroke-dash')
        . $r(64, 104, 112, 20, 'ha-bg', 10) . $path('M112 114h16', 'ha-stroke-danger'),

    'collabs.title' =>
        $r(14, 52, 212, 46, 'ha-panel', 8)
        . $bar(26, 72.5, 70, 'ha-text')
        . $r(104, 64, 66, 22, 'ha-twitchbg', 6) . $bar(112, 72.5, 50, 'ha-twitch')
        . $r(176, 64, 40, 22, 'ha-okbg', 6) . $bar(182, 72.5, 28, 'ha-ok'),

    'overlays.add' =>
        $r(14, 20, 100, 110, 'ha-panel', 8) . $bar(24, 32, 60, 'ha-text')
        . $r(24, 46, 80, 18, 'ha-bg', 5) . $bar(30, 52.5, 50) . $path('M92 53l4 4 4-4', 'ha-stroke')
        . $r(24, 72, 80, 18, 'ha-bg', 5) . $bar(30, 78.5, 40)
        . $r(24, 100, 60, 18, 'ha-accent', 6) . $bar(34, 106.5, 40, 'ha-white-fill')
        . $path('M120 75h20', 'ha-stroke-accent') . $path('M134 69l7 6-7 6', 'ha-stroke-accent')
        . $r(150, 30, 76, 36, 'ha-panel', 6) . $dot(166, 48, 8, 'ha-soft') . $bar(180, 45.5, 36, 'ha-text')
        . $r(150, 74, 76, 36, 'ha-panel', 6) . $dot(166, 92, 8, 'ha-twitchbg') . $bar(180, 89.5, 30, 'ha-text'),

    'overlays.obs' =>
        $r(12, 22, 140, 100, 'ha-bg', 8) . $r(12, 22, 140, 14, 'ha-panel', 8) . $dot(22, 29, 2.5, 'ha-danger') . $dot(30, 29, 2.5, 'ha-warn') . $dot(38, 29, 2.5, 'ha-ok')
        . $r(26, 46, 112, 62, 'ha-panel', 4) . $r(70, 86, 60, 16, 'ha-twitchbg', 4) . $bar(76, 91.5, 40, 'ha-twitch')
        . $r(162, 40, 66, 70, 'ha-panel', 7) . $bar(170, 50, 40, 'ha-text') . $r(170, 62, 50, 12, 'ha-soft', 4) . $bar(174, 65.5, 40, 'ha-accent')
        . $bar(170, 82, 22, 'ha-text') . $bar(198, 82, 22, 'ha-text') . $r(170, 94, 34, 10, 'ha-accent', 5),

    'overlays.settings' =>
        $r(10, 18, 104, 116, 'ha-panel', 8)
        . $bar(20, 30, 40, 'ha-text') . $r(20, 40, 84, 12, 'ha-bg', 4)
        . $bar(20, 60, 30, 'ha-text') . $r(20, 70, 40, 12, 'ha-bg', 4) . $r(66, 70, 38, 12, 'ha-accent', 4)
        . $bar(20, 92, 36, 'ha-text') . $r(20, 102, 84, 4, 'ha-line', 2) . $dot(78, 104, 5, 'ha-accent')
        . $r(122, 30, 108, 66, 'ha-bg', 6) . $r(146, 50, 60, 26, 'ha-panel', 5) . $path('M146 50h60v26h-60z', 'ha-stroke-accent') . $bar(154, 60.5, 44, 'ha-text')
        . $r(122, 104, 50, 16, 'ha-soft', 5) . $r(180, 104, 50, 16, 'ha-panel', 5) . $path('M150 108l6 4-6 4z', 'ha-accent'),

    'overlays.viewers' =>
        $r(14, 16, 212, 20, 'ha-bg', 6) . $r(18, 19, 70, 14, 'ha-panel', 5) . $r(92, 19, 70, 14, 'ha-soft', 5) . $bar(102, 23.5, 50, 'ha-accent')
        . implode('', array_map(static fn (int $i): string => sprintf(
            '<rect x="14" y="%d" width="212" height="40" rx="7" class="ha-panel"/><circle cx="32" cy="%d" r="9" class="%s"/><rect x="48" y="%d" width="60" height="5" rx="2.5" class="ha-text"/><rect x="120" y="%d" width="64" height="12" rx="4" class="ha-bg"/><path d="M196 %dl6 4-6 4z" class="ha-accent"/>',
            44 + $i * 48, 64 + $i * 48, $i === 0 ? 'ha-twitchbg' : 'ha-okbg', 61.5 + $i * 48, 58 + $i * 48, 60 + $i * 48
        ), range(0, 1))),

    'overlays.bot' =>
        $r(14, 20, 120, 110, 'ha-panel', 8)
        . $bar(24, 34, 70, 'ha-text') . $r(24, 46, 70, 12, 'ha-twitchbg', 6) . $bar(30, 49.5, 50, 'ha-twitch')
        . $bar(24, 70, 60, 'ha-text') . $r(24, 82, 96, 30, 'ha-soft', 6) . $bar(30, 89, 70, 'ha-accent') . $bar(30, 99, 50, 'ha-accent')
        . $path('M138 90c20 0 24-30 40-30', 'ha-stroke-accent-dash') . $path('M172 54l7 6-8 5', 'ha-stroke-accent')
        . $r(176, 30, 54, 70, 'ha-bg', 8) . $dot(203, 52, 12, 'ha-accent') . $r(195, 47, 16, 10, 'ha-white-fill', 3)
        . $dot(199, 52, 1.6, 'ha-accent') . $dot(207, 52, 1.6, 'ha-accent') . $bar(186, 78, 34, 'ha-text') . $bar(190, 88, 26),

    'overlays.advanced' =>
        $r(14, 16, 212, 20, 'ha-bg', 6) . $r(18, 19, 60, 14, 'ha-panel', 5) . $r(82, 19, 60, 14, 'ha-accent', 5) . $bar(90, 23.5, 44, 'ha-white-fill')
        . $r(14, 42, 130, 94, 'ha-panel', 7)
        . implode('', array_map(static fn (int $i): string => sprintf(
            '<rect x="24" y="%d" width="%d" height="5" rx="2.5" class="%s"/>', 54 + $i * 12, [40, 70, 56, 30, 64, 48, 60][$i], $i === 0 || $i === 3 ? 'ha-line' : ($i === 4 ? 'ha-accent' : 'ha-text')
        ), range(0, 6)))
        . $r(152, 42, 74, 94, 'ha-bg', 7) . $bar(162, 54, 40, 'ha-twitch') . $bar(170, 66, 46, 'ha-text') . $bar(170, 78, 34, 'ha-text') . $bar(162, 90, 10, 'ha-twitch')
        . $r(162, 104, 54, 22, 'ha-warnbg', 5) . $bar(168, 112.5, 40, 'ha-warn'),

    'overlays.link' =>
        $r(16, 46, 150, 26, 'ha-panel', 6) . implode('', array_map(static fn (int $i): string => sprintf('<circle cx="%d" cy="59" r="2.6" class="ha-text"/>', 28 + $i * 10), range(0, 12)))
        . $r(172, 46, 52, 26, 'ha-soft', 6) . $bar(182, 56.5, 32, 'ha-accent')
        . $r(30, 86, 180, 46, 'ha-bg', 8) . $r(44, 98, 22, 22, 'ha-accent', 6) . $path('M50 108v-3a5 5 0 0 1 10 0v3', 'ha-stroke-white') . $r(48, 108, 14, 9, 'ha-white-fill', 2)
        . $bar(76, 102, 110, 'ha-text') . $bar(76, 114, 80),

    'media.upload' =>
        $r(16, 18, 208, 54, 'ha-bg', 10) . $path('M16 18h208v54H16z', 'ha-stroke-accent-dash')
        . $path('M120 30v24M110 40l10-10 10 10', 'ha-stroke-accent')
        . $r(16, 84, 98, 50, 'ha-panel', 8) . $dot(36, 109, 12, 'ha-soft') . $path('M33 103v12l9-6z', 'ha-accent') . $bar(54, 102, 50, 'ha-text') . $bar(54, 112, 30)
        . $r(126, 84, 98, 50, 'ha-panel', 8) . $r(134, 92, 34, 34, 'ha-warnbg', 6) . $dot(151, 109, 9, 'ha-warn') . $bar(176, 102, 40, 'ha-text') . $bar(176, 112, 26),

    'media.sprites' =>
        $r(14, 24, 212, 40, 'ha-panel', 8)
        . implode('', array_map(static fn (int $i): string => sprintf('<rect x="%d" y="%d" width="4" height="%d" rx="2" class="%s"/>', 22 + $i * 8, 44 - [6, 12, 8, 16, 4, 2, 10, 14, 6, 3, 12, 16, 8, 4, 2, 9, 13, 5, 3, 11, 15, 7, 4, 10, 6][$i % 25], 2 * [6, 12, 8, 16, 4, 2, 10, 14, 6, 3, 12, 16, 8, 4, 2, 9, 13, 5, 3, 11, 15, 7, 4, 10, 6][$i % 25], ($i >= 2 && $i <= 7) ? 'ha-accent' : (($i >= 15 && $i <= 20) ? 'ha-ok' : 'ha-line')), range(0, 24)))
        . $r(14, 76, 104, 26, 'ha-soft', 6) . $bar(22, 86.5, 50, 'ha-accent') . $path('M96 84l6 5-6 5z', 'ha-accent')
        . $r(122, 76, 104, 26, 'ha-okbg', 6) . $bar(130, 86.5, 50, 'ha-ok') . $path('M204 84l6 5-6 5z', 'ha-ok')
        . $r(14, 112, 64, 22, 'ha-panel', 6) . $bar(22, 120.5, 44, 'ha-text'),

    'media.pick' =>
        $r(14, 24, 120, 104, 'ha-panel', 8) . $bar(24, 36, 40, 'ha-text') . $r(24, 46, 100, 16, 'ha-bg', 5) . $bar(30, 51.5, 50)
        . $r(24, 66, 100, 46, 'ha-panel', 5) . $r(28, 70, 92, 12, 'ha-soft', 4) . $bar(34, 73.5, 50, 'ha-accent') . $bar(34, 88, 60) . $bar(34, 100, 40)
        . $path('M138 76h22', 'ha-stroke-accent') . $path('M154 70l7 6-7 6', 'ha-stroke-accent')
        . $r(166, 40, 62, 72, 'ha-bg', 8) . $r(178, 60, 38, 30, 'ha-panel', 5) . $path('M190 70l12 5-12 5z', 'ha-accent'),

    'media.shared' =>
        $r(14, 30, 96, 92, 'ha-panel', 8) . $dot(36, 50, 9, 'ha-soft') . $bar(52, 47.5, 46, 'ha-text') . $dot(36, 76, 9, 'ha-soft') . $bar(52, 73.5, 40, 'ha-text') . $dot(36, 102, 9, 'ha-soft') . $bar(52, 99.5, 50, 'ha-text')
        . $r(130, 30, 96, 92, 'ha-bg', 8) . $r(140, 40, 76, 16, 'ha-accent', 5) . $bar(148, 45.5, 50, 'ha-white-fill')
        . $dot(152, 76, 9, 'ha-okbg') . $bar(168, 73.5, 40, 'ha-text') . $dot(152, 102, 9, 'ha-okbg') . $bar(168, 99.5, 46, 'ha-text'),

    'bot.add' =>
        $r(14, 22, 130, 106, 'ha-panel', 8) . $bar(26, 36, 70, 'ha-text') . $r(110, 32, 26, 12, 'ha-okbg', 6)
        . $bar(26, 56, 100) . $bar(26, 68, 80) . $r(26, 92, 90, 22, 'ha-accent', 6) . $bar(38, 100.5, 66, 'ha-white-fill')
        . $path('M148 75h18', 'ha-stroke-accent') . $path('M160 69l7 6-7 6', 'ha-stroke-accent')
        . $r(172, 34, 56, 82, 'ha-twitchbg', 8) . $dot(200, 62, 14, 'ha-twitch') . $r(191, 56, 18, 12, 'ha-white-fill', 3) . $bar(184, 90, 32, 'ha-twitch'),

    'bot.access' =>
        $r(14, 30, 100, 94, 'ha-panel', 8) . $dot(64, 58, 14, 'ha-okbg') . $path('M57 58l5 5 9-10', 'ha-stroke-ok') . $bar(30, 84, 68, 'ha-text') . $bar(38, 96, 52)
        . $bar(116, 75, 8, 'ha-text')
        . $r(126, 30, 100, 94, 'ha-panel', 8) . $dot(176, 58, 14, 'ha-soft') . $path('M170 52l12 12M182 52l-12 12', 'ha-stroke-accent') . $bar(142, 84, 68, 'ha-text') . $r(150, 96, 52, 12, 'ha-bg', 4),

    'bot.commands' =>
        $r(14, 18, 212, 116, 'ha-panel', 8)
        . implode('', array_map(static fn (int $i): string => sprintf(
            '<rect x="26" y="%d" width="40" height="14" rx="4" class="ha-soft"/><rect x="32" y="%d" width="28" height="5" rx="2.5" class="ha-accent"/><rect x="76" y="%d" width="%d" height="5" rx="2.5" class="ha-text"/><rect x="180" y="%d" width="34" height="12" rx="6" class="%s"/>',
            30 + $i * 26, 34.5 + $i * 26, 34.5 + $i * 26, [90, 70, 84, 60][$i], 31 + $i * 26, $i === 2 ? 'ha-bg' : 'ha-okbg'
        ), range(0, 3))),

    'bot.custom' =>
        $r(14, 20, 212, 112, 'ha-panel', 8)
        . $bar(26, 34, 40, 'ha-text') . $r(26, 44, 80, 16, 'ha-bg', 5) . $bar(32, 49.5, 10, 'ha-accent') . $bar(46, 49.5, 40, 'ha-text')
        . $bar(124, 34, 40, 'ha-text') . $r(124, 44, 90, 16, 'ha-bg', 5) . $bar(130, 49.5, 60)
        . $bar(26, 72, 40, 'ha-text') . $r(26, 82, 188, 34, 'ha-bg', 5) . $bar(32, 90, 120, 'ha-text') . $r(156, 88, 44, 9, 'ha-soft', 4) . $bar(32, 102, 80),

    'bot.timers' =>
        $dot(64, 75, 42, 'ha-panel') . $dot(64, 75, 34, 'ha-bg') . $path('M64 75V52M64 75l16 10', 'ha-stroke-accent') . $dot(64, 75, 3, 'ha-accent')
        . $r(122, 30, 104, 26, 'ha-panel', 6) . $bar(132, 40.5, 70, 'ha-text')
        . $r(122, 62, 104, 26, 'ha-soft', 6) . $bar(132, 72.5, 80, 'ha-accent')
        . $r(122, 94, 104, 26, 'ha-panel', 6) . $bar(132, 104.5, 60, 'ha-text'),

    'bot.logs' =>
        $r(14, 20, 120, 112, 'ha-panel', 8)
        . implode('', array_map(static fn (int $i): string => sprintf('<circle cx="28" cy="%d" r="3" class="%s"/><rect x="38" y="%d" width="%d" height="5" rx="2.5" class="ha-text"/>', 36 + $i * 16, $i === 3 ? 'ha-warn' : 'ha-ok', 33.5 + $i * 16, [80, 66, 84, 70, 58, 76][$i]), range(0, 5)))
        . $r(144, 20, 82, 112, 'ha-bg', 8)
        . implode('', array_map(static fn (int $i): string => sprintf('<rect x="%d" y="%d" width="12" height="%d" rx="3" class="ha-accent"/>', 156 + $i * 18, 120 - [40, 64, 52, 80][$i], [40, 64, 52, 80][$i]), range(0, 3))),
];
?>
<svg viewBox="0 0 240 150" class="ha" role="presentation"><?= $arts[$art] ?? '' ?></svg>
