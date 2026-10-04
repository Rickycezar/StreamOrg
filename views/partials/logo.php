<?php
/**
 * The mark, inlined rather than an <img> so CSS can reach its parts —
 * the hands animate on hover and the stroke follows currentColor.
 *
 * @var string $class  wrapper class
 * @var int    $w
 * @var int    $h
 */
$class = $class ?? 'brand-mark';
$w = $w ?? 36;
$h = $h ?? 30;
?>
<svg class="<?= e($class) ?>" width="<?= (int) $w ?>" height="<?= (int) $h ?>"
     viewBox="0 0 144 120" aria-hidden="true" focusable="false">
    <defs>
        <clipPath id="so-band-<?= e($class) ?>">
            <rect x="59.5" y="37.5" width="63" height="11"/>
        </clipPath>
    </defs>
    <g fill="none" stroke="currentColor" stroke-width="7"
       stroke-linecap="round" stroke-linejoin="round">
        <path d="M56 22 A38 38 0 0 0 56 98"/>
        <path class="so-minute" d="M56 60 L44 35"/>
        <path class="so-hour"   d="M56 60 L36 67"/>
        <path d="M56 34 H126 V88 H56 Z"/>
        <path d="M56 52 H126"/>
    </g>
    <g clip-path="url(#so-band-<?= e($class) ?>)" stroke="currentColor" stroke-width="6" stroke-linecap="butt">
        <path d="M62 34 L54 52"/><path d="M78 34 L70 52"/><path d="M94 34 L86 52"/>
        <path d="M110 34 L102 52"/><path d="M126 34 L118 52"/>
    </g>
    <circle cx="56" cy="60" r="3.5" fill="currentColor"/>
</svg>
