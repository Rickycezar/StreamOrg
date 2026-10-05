<?php
/** @var string $json @var int $count @var int $shown @var bool $enabled
 *  @var list<array{index:int, name:string, quote:string, avatar:?string, active:bool}> $items */

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
    "rating": 5,
    "active": true
  }
]
JSON;
?>
<h1><?= e(__('ui.nav.testimonials')) ?></h1>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.testimonials_on_page')) ?></h2>
        <form method="post" action="<?= e(url('/admin/testimonials/section')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
            <button type="submit" class="switch<?= $enabled ? ' on' : '' ?>" aria-pressed="<?= $enabled ? 'true' : 'false' ?>">
                <span class="switch-track"><span class="switch-thumb"></span></span>
                <?= e(__($enabled ? 'ui.label.testimonials_section_on' : 'ui.label.testimonials_section_off')) ?>
            </button>
        </form>
    </div>

    <?php if ($items === []): ?>
        <p class="empty"><?= e(__('ui.message.testimonials_none')) ?></p>
    <?php else: ?>
        <p class="muted small"><?= e(sprintf(__('ui.message.testimonials_toggle_hint'), $shown, $count)) ?></p>
        <ul class="testimonial-list<?= $enabled ? '' : ' section-off' ?>">
            <?php foreach ($items as $item): ?>
                <li class="<?= $item['active'] ? '' : 'off' ?>">
                    <?php if ($item['avatar']): ?>
                        <img src="<?= e($item['avatar']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                    <?php else: ?>
                        <span class="testimonial-initial"><?= e(mb_strtoupper(mb_substr($item['name'], 0, 1))) ?></span>
                    <?php endif; ?>
                    <span class="testimonial-text">
                        <strong><?= e($item['name']) ?></strong>
                        <small class="muted"><?= e(mb_strimwidth($item['quote'], 0, 110, '…')) ?></small>
                    </span>
                    <form method="post" action="<?= e(url('/admin/testimonials/toggle')) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="index" value="<?= (int) $item['index'] ?>">
                        <input type="hidden" name="active" value="<?= $item['active'] ? '0' : '1' ?>">
                        <button type="submit" class="switch<?= $item['active'] ? ' on' : '' ?>" aria-pressed="<?= $item['active'] ? 'true' : 'false' ?>"
                                aria-label="<?= e(sprintf(__($item['active'] ? 'ui.action.hide_testimonial' : 'ui.action.show_testimonial'), $item['name'])) ?>">
                            <span class="switch-track"><span class="switch-thumb"></span></span>
                            <?= e(__($item['active'] ? 'ui.label.shown' : 'ui.label.hidden')) ?>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= e(__('ui.label.testimonials_file')) ?></h2>
        <span class="badge <?= $enabled && $shown > 0 ? 'ok' : 'off' ?>">
            <?= e($enabled && $shown > 0 ? sprintf(__('ui.label.n_testimonials'), $shown) : __('ui.label.testimonials_hidden')) ?>
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
