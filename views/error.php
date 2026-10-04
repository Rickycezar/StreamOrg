<?php /** @var string $heading @var string $message */ ?>
<div class="card narrow">
    <h1><?= e($heading) ?></h1>
    <p class="muted"><?= e($message) ?></p>
    <p><a class="btn" href="<?= e(url(Auth::check() ? '/dashboard' : '/')) ?>"><?= e(__('ui.action.back')) ?></a></p>
</div>
