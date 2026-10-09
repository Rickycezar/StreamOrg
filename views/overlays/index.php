<?php
/**
 * The streamer's overlays: add one from a type, and for each its link for
 * OBS (copy), whether it is on and open somewhere, settings and delete.
 *
 * @var list<array> $overlays @var list<string> $types @var bool $enabled @var int $limit
 */
$openSince = time() - 600;
?>
<?php if (!$enabled): ?>
    <p class="notice warn"><?= e(__('ui.overlay.all_off')) ?></p>
<?php endif; ?>

<section class="card">
    <h2><?= e(__('ui.overlay.add_title')) ?></h2>
    <p class="muted small"><?= e(__('ui.overlay.intro')) ?></p>

    <?php if ($enabled && $types !== [] && count($overlays) < $limit): ?>
        <form method="post" action="<?= e(url('/overlays')) ?>" class="subform">
            <?= Csrf::field() ?>
            <div class="grid">
                <label>
                    <span><?= e(__('ui.overlay.type')) ?></span>
                    <select name="type" required>
                        <?php foreach ($types as $type): ?>
                            <option value="<?= e($type) ?>"><?= e(__('ui.overlay_type.' . $type)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?= e(__('ui.field.name')) ?></span>
                    <input type="text" name="name" maxlength="80" placeholder="<?= e(__('ui.overlay.name_hint')) ?>">
                </label>
            </div>
            <button type="submit" class="btn primary"><?= e(__('ui.overlay.add')) ?></button>
        </form>
    <?php elseif ($enabled && $types !== []): ?>
        <p class="muted"><?= e(sprintf(__('ui.message.overlay_limit'), $limit)) ?></p>
    <?php endif; ?>
</section>

<?php if ($overlays === []): ?>
    <p class="empty"><?= e(__('ui.overlay.none')) ?></p>
<?php else: ?>
    <div class="overlay-list">
        <?php foreach ($overlays as $o):
            $open = $o['last_seen_at'] !== null && strtotime((string) $o['last_seen_at']) > $openSince; ?>
            <section class="card overlay-item">
                <div class="card-head">
                    <h2><a href="<?= e(url('/overlays/edit?id=' . $o['id'])) ?>"><?= e($o['name']) ?></a></h2>
                    <span class="badge"><?= e(__('ui.overlay_type.' . $o['type'])) ?></span>
                    <?php if (!$o['enabled']): ?>
                        <span class="badge off"><?= e(__('ui.overlay.state_off')) ?></span>
                    <?php elseif ($open): ?>
                        <span class="badge ok" title="<?= e(fmt_datetime($o['last_seen_at'])) ?>"><?= e(__('ui.overlay.state_open')) ?></span>
                    <?php else: ?>
                        <span class="badge" title="<?= e($o['last_seen_at'] !== null ? fmt_datetime($o['last_seen_at']) : '') ?>"><?= e(__('ui.overlay.state_closed')) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($o['link'] !== null): ?>
                    <div class="overlay-link">
                        <input type="password" readonly value="<?= e($o['link']) ?>" id="overlay-link-<?= $o['id'] ?>" aria-label="<?= e(__('ui.overlay.link')) ?>" data-reveal-on-focus>
                        <button type="button" class="btn small" data-copy="#overlay-link-<?= $o['id'] ?>"><?= e(__('ui.overlay.copy_link')) ?></button>
                    </div>
                <?php endif; ?>

                <div class="card-actions">
                    <a class="btn small primary" href="<?= e(url('/overlays/edit?id=' . $o['id'])) ?>"><?= e(__('ui.overlay.settings')) ?></a>
                    <form method="post" action="<?= e(url('/overlays/delete')) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= $o['id'] ?>">
                        <button type="submit" class="btn small danger-btn" data-confirm="<?= e(__('ui.overlay.delete_confirm')) ?>"><?= e(__('ui.action.delete')) ?></button>
                    </form>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <p class="muted small"><?= e(__('ui.overlay.link_secret')) ?></p>
<?php endif; ?>
