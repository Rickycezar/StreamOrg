<?php
/** @var string $json @var int $count */

$example = <<<'JSON'
[
  {
    "name": "RafaLimaTV",
    "quote": {
      "en": "StreamOrg saved me from missing deadlines.",
      "pt-BR": "O StreamOrg me salvou de perder prazos."
    },
    "detail": { "en": "Variety · 48k followers", "pt-BR": "Variedade · 48 mil seguidores" },
    "avatar": "https://example.com/avatar.png",
    "url": "https://twitch.tv/rafalimatv",
    "rating": 5
  }
]
JSON;
?>
<h1><?= e(__('ui.nav.testimonials')) ?></h1>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.testimonials_file')) ?></h2>
        <span class="badge <?= $count > 0 ? 'ok' : 'off' ?>">
            <?= e($count > 0 ? sprintf(__('ui.label.n_testimonials'), $count) : __('ui.label.testimonials_hidden')) ?>
        </span>
    </div>
    <p class="muted small"><?= e(__('ui.message.testimonials_explain')) ?></p>

    <form method="post" action="<?= e(url('/admin/testimonials')) ?>" enctype="multipart/form-data" class="subform">
        <?= Csrf::field() ?>
        <label>
            <span><?= e(__('ui.field.testimonials_upload')) ?></span>
            <input type="file" name="file" accept=".json,application/json">
        </label>

        <label>
            <span><?= e(__('ui.field.testimonials_json')) ?></span>
            <textarea name="json" rows="18" class="code-editor" spellcheck="false"><?= e($json) ?></textarea>
        </label>

        <div class="card-actions">
            <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
            <a class="btn" href="<?= e(url('/admin/testimonials/download')) ?>" data-turbo="false"><?= e(__('ui.action.download_json')) ?></a>
            <a class="btn ghost" href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= e(__('ui.action.view_landing')) ?></a>
        </div>
    </form>
</section>

<section class="card">
    <h2><?= e(__('ui.label.testimonials_format')) ?></h2>
    <p class="muted small"><?= e(__('ui.message.testimonials_format')) ?></p>
    <pre class="code-sample"><?= e($example) ?></pre>
</section>
