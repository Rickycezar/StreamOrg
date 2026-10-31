<?php
/**
 * A giveaway's draw by the boss battle game: the game in a sandboxed frame
 * (it reads the creator's chat itself), the options for the battle drawn
 * from the game's manifest with the game's own texts, and buttons that
 * mirror the chat commands. app.js speaks the integration contract with
 * the frame and records the confirmed result as the giveaway's winners.
 *
 * @var array $giveaway @var string $game @var array $manifest @var string $gameUrl
 * @var array $config the first bossdraw:config @var ?int $slots winners the prizes allow, null without prizes
 * @var bool $canDraw @var list<array> $options the options the game declares @var array<string, string> $texts the game's texts
 * @var array $rules the creator's preferences, with StreamOrg's draw rules (sync_winners, exclude_winners)
 */
$id      = (int) $giveaway['id'];
$ready   = $canDraw && $config['channel'] !== '' && $slots !== 0;
$groups  = [];
foreach ($options as $option) {
    $groups[(string) ($option['group'] ?? '')][] = $option;
}
$text    = static fn (string $key, string $fallback): string => $texts[$key] ?? $fallback;
$current = (array) $config['options'];
?>
<p class="muted small"><a href="<?= e(url('/giveaways/show?id=' . $id)) ?>">← <?= e($giveaway['title']) ?></a></p>

<div class="page-head">
    <h1><?= e(__('ui.label.game_draw')) ?> <span class="badge"><?= e($giveaway['title']) ?></span></h1>
    <div class="page-actions">
        <?php $helpPage = 'giveaways'; require dirname(__DIR__) . '/partials/help_link.php'; ?>
        <a class="btn small" href="<?= e(url('/games/' . $game . '/')) ?>" target="_blank" rel="noopener" title="<?= e(__('ui.game_draw.public_hint')) ?>"><?= e(__('ui.game_draw.public')) ?> ↗</a>
    </div>
</div>

<?php if (!$canDraw): ?>
    <p class="notice"><?= e(__('ui.game_draw.ended')) ?></p>
<?php elseif ($config['channel'] === ''): ?>
    <p class="notice"><?= e(__('ui.game_draw.no_channel')) ?> <a href="<?= e(url('/profile')) ?>"><?= e(__('ui.game_draw.connect')) ?></a></p>
<?php elseif ($slots === 0): ?>
    <p class="notice"><?= e(__('ui.game_draw.no_slots')) ?></p>
<?php else: ?>
<div class="game-draw" data-game-draw data-id="<?= $id ?>" data-contract="<?= Games::CONTRACT ?>">
    <script type="application/json" data-game-draw-config><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <script type="application/json" data-game-draw-strings><?= json_encode([
        'phase'    => array_combine(['idle', 'open', 'running', 'showdown', 'result', 'done'], array_map(static fn (string $p): string => __('ui.game_draw.phase_' . $p), ['idle', 'open', 'running', 'showdown', 'result', 'done'])),
        'waiting'  => __('ui.game_draw.waiting'),
        'noAnswer' => __('ui.game_draw.no_answer'),
        'refused'  => __('ui.game_draw.refused'),
        'applied'  => __('ui.game_draw.applied'),
        'busy'     => __('ui.game_draw.busy'),
        'leave'    => __('ui.game_draw.leave'),
        'unreachable' => __('ui.game_draw.unreachable'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

    <section class="card game-draw-info">
        <p>
            <?= sprintf(e(__('ui.game_draw.intro')), '<code>' . e($config['entry']) . '</code>', '<strong>' . (int) $config['maxWinners'] . '</strong>') ?>
            <?php if ($slots === null): ?><span class="muted"><?= e(__('ui.game_draw.no_prizes')) ?></span><?php endif; ?>
        </p>
        <div class="card-actions game-draw-controls">
            <span class="badge" data-game-draw-phase><?= e(__('ui.game_draw.waiting')) ?></span>
            <button type="button" class="btn" data-game-command="open" disabled><?= e(__('ui.game_draw.cmd_open')) ?></button>
            <button type="button" class="btn" data-game-command="reopen" disabled title="<?= e(__('ui.game_draw.cmd_reopen_hint')) ?>"><?= e(__('ui.game_draw.cmd_reopen')) ?></button>
            <button type="button" class="btn primary" data-game-command="start" disabled><?= icon_label('swords', __('ui.game_draw.cmd_start')) ?></button>
            <button type="button" class="btn ghost" data-game-command="reset" disabled data-confirm-text="<?= e(__('ui.game_draw.reset_confirm')) ?>"><?= icon_label('refresh', __('ui.game_draw.cmd_reset')) ?></button>
            <button type="button" class="btn" data-game-fullscreen><?= icon_label('maximize', __('ui.game_draw.fullscreen')) ?></button>
        </div>
        <p class="game-draw-message" data-game-draw-message role="status" hidden></p>
    </section>

    <div class="game-draw-stage">
        <iframe src="<?= e($gameUrl) ?>" title="<?= e(__('ui.label.game_draw')) ?>" data-game-frame
                sandbox="allow-scripts allow-popups" allow="autoplay; fullscreen" referrerpolicy="no-referrer"></iframe>
    </div>

        <form class="card game-draw-options" data-game-draw-options autocomplete="off">
            <div class="card-head">
                <h2><?= e(__('ui.game_draw.options')) ?></h2>
            </div>
            <p class="muted small"><?= e(__('ui.game_draw.options_hint')) ?></p>
            <fieldset class="inset">
                <legend><?= e(__('ui.game_draw.rules')) ?></legend>
                <div class="grid">
                    <div>
                        <label class="inline">
                            <input type="checkbox" name="sync_winners" value="1" <?= $rules['sync_winners'] ? 'checked' : '' ?>>
                            <span><?= e(__('ui.game_draw.sync_winners')) ?></span>
                        </label>
                        <small class="muted"><?= e(__('ui.game_draw.sync_winners_hint')) ?></small>
                    </div>
                    <div>
                        <label class="inline">
                            <input type="checkbox" name="exclude_winners" value="1" <?= $rules['exclude_winners'] ? 'checked' : '' ?>>
                            <span><?= e(__('ui.game_draw.exclude_winners')) ?></span>
                        </label>
                        <small class="muted"><?= e(__('ui.game_draw.exclude_winners_hint')) ?></small>
                    </div>
                </div>
            </fieldset>
            <?php foreach ($groups as $group => $list): ?>
                <fieldset class="inset">
                    <?php if ($group !== ''): ?><legend><?= e($text('group.' . $group, $group)) ?></legend><?php endif; ?>
                    <div class="grid">
                        <?php foreach ($list as $option):
                            $key   = (string) $option['key'];
                            $name  = 'options[' . $key . ']';
                            $value = $current[$key] ?? $option['default'];
                            $label = $text('option.' . $key, $key);
                            $hint  = $texts['option.' . $key . '.hint'] ?? '';
                        ?>
                            <?php if ($option['type'] === 'bool'): ?>
                                <div>
                                    <input type="hidden" name="<?= e($name) ?>" value="0">
                                    <label class="inline">
                                        <input type="checkbox" name="<?= e($name) ?>" value="1" <?= $value ? 'checked' : '' ?>>
                                        <span><?= e($label) ?></span>
                                    </label>
                                    <?php if ($hint !== ''): ?><small class="muted"><?= e($hint) ?></small><?php endif; ?>
                                </div>
                            <?php elseif ($option['type'] === 'select'): ?>
                                <label>
                                    <span><?= e($label) ?></span>
                                    <select name="<?= e($name) ?>">
                                        <?php foreach ((array) $option['choices'] as $choice): ?>
                                            <option value="<?= e((string) $choice) ?>" <?= $value === $choice ? 'selected' : '' ?>><?= e($text('option.' . $key . '.' . $choice, (string) $choice)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($hint !== ''): ?><small class="muted"><?= e($hint) ?></small><?php endif; ?>
                                </label>
                            <?php elseif ($option['type'] === 'text'): ?>
                                <label>
                                    <span><?= e($label) ?></span>
                                    <input type="text" name="<?= e($name) ?>" value="<?= e((string) $value) ?>" maxlength="<?= min(120, (int) ($option['maxLength'] ?? 120)) ?>">
                                    <?php if ($hint !== ''): ?><small class="muted"><?= e($hint) ?></small><?php endif; ?>
                                </label>
                            <?php else: ?>
                                <label>
                                    <span><?= e($label) ?></span>
                                    <input type="number" name="<?= e($name) ?>" value="<?= e((string) $value) ?>"
                                        <?= isset($option['min']) ? 'min="' . e((string) $option['min']) . '"' : '' ?>
                                        <?= isset($option['max']) ? 'max="' . e((string) $option['max']) . '"' : '' ?>
                                        step="<?= e((string) ($option['step'] ?? ($option['type'] === 'int' ? 1 : 'any'))) ?>" required>
                                    <?php if ($hint !== ''): ?><small class="muted"><?= e($hint) ?></small><?php endif; ?>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>
            <div class="card-actions">
                <button type="submit" class="btn primary"><?= e(__('ui.game_draw.apply')) ?></button>
            </div>
        </form>
</div>
<?php endif; ?>
