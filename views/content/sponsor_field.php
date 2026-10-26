<?php
/**
 * The sponsor of content: only its tag goes in the title (and, for sites
 * that ask for it, the game's hashtag). Choosing a key from a sponsor
 * picks it here, and it is what a new key's source starts from.
 *
 * @var list<string> $sponsors key site codes that tag content @var string $selected
 */
?>
<label>
    <span><?= e(__('ui.field.sponsor')) ?> <small class="muted"><?= e(__('ui.label.tag_only')) ?></small></span>
    <select data-content-sponsor>
        <option value=""><?= e(__('ui.label.none')) ?></option>
        <?php foreach ($sponsors as $code): ?>
            <option value="<?= e($code) ?>"<?= $code === $selected ? ' selected' : '' ?>><?= e(code_label('key_platform', $code)) ?></option>
        <?php endforeach; ?>
    </select>
</label>
