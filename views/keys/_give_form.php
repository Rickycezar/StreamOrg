<?php
/**
 * "Give a key": one key from the vault straight to one person on Twitch,
 * without a giveaway. They get a claim link, as a giveaway winner would
 * (see Giveaways::giveDirect). The vault's Give button picks the key;
 * otherwise it is chosen here, keys marked for giveaway first.
 *
 * @var list<array> $giftable keys that can be given
 */
?>
<form id="give-key" method="post" action="<?= e(url('/keys/give')) ?>" class="subform hidden" autocomplete="off">
    <?= Csrf::field() ?>
    <p class="muted small"><?= e(__('ui.message.give_key_explain')) ?></p>
    <label>
        <span><?= e(__('ui.field.key')) ?></span>
        <select name="key_id" required data-give-key-select>
            <?php $group = null;
            foreach ($giftable as $key):
                if ($group !== $key['status']):
                    if ($group !== null): ?></optgroup><?php endif;
                    $group = $key['status']; ?>
                    <optgroup label="<?= e(__($group === 'for_giveaway' ? 'ui.label.prize_group_marked' : 'ui.label.prize_group_available')) ?>">
                <?php endif; ?>
                <option value="<?= (int) $key['id'] ?>"><?= e($key['title'] . ' · ' . code_label('game_platform', $key['platform_code'])) ?></option>
            <?php endforeach; ?>
            <?php if ($group !== null): ?></optgroup><?php endif; ?>
        </select>
    </label>
    <div class="twitch-pick" data-twitch-pick data-search-url="<?= e(url('/giveaways/winners/search')) ?>" data-empty="<?= e(__('ui.label.picker_none')) ?>">
        <label>
            <span><?= e(__('ui.field.twitch_user')) ?></span>
            <input type="search" name="login" required autocomplete="off" spellcheck="false" placeholder="<?= e(__('ui.label.winner_search_hint')) ?>" data-twitch-pick-search>
        </label>
        <input type="hidden" name="twitch_id" value="" data-twitch-pick-id>
        <div class="twitch-pick-chosen" data-twitch-pick-chosen hidden>
            <img alt="" data-twitch-pick-avatar>
            <span><strong data-twitch-pick-name></strong> <small class="muted" data-twitch-pick-login></small></span>
            <button type="button" class="btn small" data-twitch-pick-change><?= e(__('ui.action.change')) ?></button>
        </div>
        <div class="cast-add-results twitch-pick-results" data-twitch-pick-results></div>
    </div>
    <label>
        <span><?= e(__('ui.field.claim_days')) ?></span>
        <span class="input-suffix">
            <input type="number" name="claim_days" value="30" min="1" max="365" required>
            <span class="muted"><?= e(__('ui.label.unit_days')) ?></span>
        </span>
    </label>
    <div class="card-actions">
        <button type="submit" class="btn primary"><?= e(__('ui.action.give_key')) ?></button>
        <button type="button" class="btn row-cancel"><?= e(__('ui.action.cancel')) ?></button>
    </div>
</form>
