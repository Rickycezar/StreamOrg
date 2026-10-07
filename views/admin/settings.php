<?php
/** @var int $lifetimeValue @var string $lifetimeUnit @var list<string> $units
 *  @var int $minMinutes @var int $maxDays
 *  @var array<string,string> $domains domain => locale @var list<string> $fixedDomains */
?>
<h1><?= e(__('ui.nav.settings')) ?></h1>

<section class="card">
    <h2><?= e(__('ui.label.sessions')) ?></h2>
    <p class="muted small"><?= e(__('ui.message.session_lifetime_hint')) ?></p>

    <form method="post" action="<?= e(url('/admin/settings')) ?>" class="subform">
        <?= Csrf::field() ?>
        <div class="grid">
            <label>
                <span><?= e(__('ui.field.session_lifetime')) ?></span>
                <input type="number" name="session_lifetime" min="1" step="1" required
                       value="<?= (int) $lifetimeValue ?>">
            </label>
            <label>
                <span>&nbsp;</span>
                <select name="session_lifetime_unit">
                    <?php foreach ($units as $unit): ?>
                        <option value="<?= e($unit) ?>" <?= $unit === $lifetimeUnit ? 'selected' : '' ?>>
                            <?= e(__('ui.label.unit_' . $unit)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <p class="muted small"><?= e(sprintf(__('ui.message.session_lifetime_range'), $minMinutes, $maxDays)) ?></p>
        <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(__('ui.label.domains')) ?></h2>
    <p class="muted small"><?= e(__('ui.message.domains_hint')) ?></p>

    <?php if ($domains === []): ?>
        <p class="muted"><?= e(__('ui.message.domains_none')) ?></p>
    <?php else: ?>
        <form method="post" action="<?= e(url('/admin/settings/domains')) ?>" class="subform">
            <?= Csrf::field() ?>
            <ul class="domain-list">
                <?php foreach ($domains as $domain => $locale): ?>
                    <li>
                        <span class="domain-name">
                            <strong><?= e($domain) ?></strong>
                            <small class="muted"><?= e(Lang::t('ui.label.language_name', $locale)) ?></small>
                        </span>
                        <label class="inline">
                            <input type="checkbox" name="fixed[]" value="<?= e($domain) ?>" <?= in_array($domain, $fixedDomains, true) ? 'checked' : '' ?>>
                            <span><?= e(__('ui.label.domain_fixed')) ?></span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="muted small"><?= e(__('ui.message.domain_fixed_hint')) ?></p>
            <button type="submit" class="btn primary"><?= e(__('ui.action.save')) ?></button>
        </form>
    <?php endif; ?>
</section>
