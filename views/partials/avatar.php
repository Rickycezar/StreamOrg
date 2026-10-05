<?php
/**
 * A user's round avatar: their picture, or their initials on a colour
 * derived from the username.
 *
 * @var array  $avatarUser  a users row; 'avatar_url' may carry a ready URL
 * @var string $avatarSize  sm | md | xl
 */
$avatarUrl  = $avatarUser['avatar_url'] ?? Avatars::url($avatarUser);
$avatarName = (string) (($avatarUser['display_name'] ?? '') ?: ($avatarUser['username'] ?? '?'));
preg_match_all('/\b\p{L}|\p{Lu}/u', $avatarName, $avatarLetters);
$avatarInitials = mb_strtoupper(implode('', array_slice(array_unique($avatarLetters[0]), 0, 2))) ?: '?';
$avatarHue = hexdec(substr(md5((string) ($avatarUser['username'] ?? $avatarName)), 0, 2)) * 360 / 256;
?>
<span class="avatar avatar-<?= e($avatarSize ?? 'md') ?>"<?= $avatarUrl ? '' : ' style="--avatar-hue: ' . (int) $avatarHue . '"' ?>>
    <?php if ($avatarUrl): ?>
        <img src="<?= e($avatarUrl) ?>" alt="">
    <?php else: ?>
        <span class="avatar-initials" aria-hidden="true"><?= e($avatarInitials) ?></span>
    <?php endif; ?>
</span>
