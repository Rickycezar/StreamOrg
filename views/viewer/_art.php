<?php
/**
 * A game's box art in a tall frame (see GameImages::boxArt): the portrait,
 * or a wide picture shown whole over a blurred copy of itself.
 *
 * @var ?array{url:string, wide:bool} $art @var string $class the frame's class
 */
?>
<span class="<?= e($class) ?><?= !empty($art['wide']) ? ' is-wide' : '' ?>"><?php if ($art !== null): ?><?php if ($art['wide']): ?><img class="art-blur" src="<?= e($art['url']) ?>" alt="" loading="lazy"><?php endif; ?><img src="<?= e($art['url']) ?>" alt="" loading="lazy"><?php endif; ?></span>
