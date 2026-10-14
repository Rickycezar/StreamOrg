<?php
/**
 * "Give a key": one key from the vault straight to one person on Twitch,
 * without a giveaway. They get a claim link, as a giveaway winner would
 * (see Giveaways::giveDirect). The vault's Give button picks the key;
 * otherwise the game is searched for (games with keys marked for
 * giveaway first) and then one of its keys chosen.
 *
 * @var list<array> $giftable keys that can be given
 */
?>
<form id="give-key" method="post" action="<?= e(url('/keys/give')) ?>" class="subform hidden" autocomplete="off">
    <?= Csrf::field() ?>
    <p class="muted small"><?= e(__('ui.message.give_key_explain')) ?></p>
    <?php $games = [];
    foreach ($giftable as $key) {
        $games[(int) $key['game_id']] ??= ['title' => $key['title'], 'count' => 0, 'marked' => false];
        $games[(int) $key['game_id']]['count']++;
        $games[(int) $key['game_id']]['marked'] = $games[(int) $key['game_id']]['marked'] || $key['status'] === 'for_giveaway';
    } ?>
    <label>
        <span><?= e(__('ui.field.game')) ?></span>
        <select required data-give-game data-placeholder="<?= e(__('ui.label.picker_search')) ?>">
            <option value=""></option>
            <?php foreach ([true, false] as $marked):
                $list = array_filter($games, static fn (array $g): bool => $g['marked'] === $marked);
                if ($list === []) continue; ?>
                <optgroup label="<?= e(__($marked ? 'ui.label.prize_group_marked' : 'ui.label.prize_group_available')) ?>">
                    <?php foreach ($list as $gameId => $game): ?>
                        <option value="<?= (int) $gameId ?>"><?= e($game['title']) ?><?= $game['count'] > 1 ? ' (' . (int) $game['count'] . ')' : '' ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span><?= e(__('ui.field.key')) ?></span>
        <select name="key_id" required disabled data-give-key-select>
            <option value=""></option>
            <?php foreach ($giftable as $key): ?>
                <option value="<?= (int) $key['id'] ?>" data-game="<?= (int) $key['game_id'] ?>"><?= e(implode(' · ', array_filter([
                    code_label('game_platform', $key['platform_code']),
                    code_label('key_type', $key['key_type']),
                    $key['expires_at'] ? __('ui.label.redeem_by') . ' ' . fmt_date($key['expires_at']) : null,
                    $key['status'] === 'for_giveaway' ? code_label('key_status', 'for_giveaway') : null,
                ]))) ?></option>
            <?php endforeach; ?>
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
