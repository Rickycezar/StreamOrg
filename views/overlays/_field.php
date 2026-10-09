<?php
/**
 * One setting in the simple form, drawn by its kind (see OverlayTypes).
 * Sounds and images are picked from the media library; a sound also by
 * part (sprite segment) and volume. Rules per viewer (maps with simple
 * sub-fields) are rows that can be added and removed.
 *
 * @var string $type @var string $name @var array $field @var mixed $value
 * @var list<array> $sounds @var list<array> $images @var string $botStatus
 */
$botStatus ??= 'active';
$input = 'settings[' . $name . ']';
$label = __('ui.overlay_field.' . $name);
$hint  = Lang::t('ui.overlay_ini.' . $type . '_' . $name);
$hint  = $hint !== 'ui.overlay_ini.' . $type . '_' . $name ? $hint : '';

$mediaOptions = static function (array $list, ?int $chosen): string {
    $html = '';

    foreach ([0 => 'ui.overlay.mine', 1 => 'ui.overlay.shared'] as $shared => $group) {
        $items = array_filter($list, static fn (array $m): bool => $m['shared'] === (bool) $shared);

        if ($items === []) {
            continue;
        }

        $html .= '<optgroup label="' . e(__($group)) . '">';
        foreach ($items as $m) {
            $html .= '<option value="' . $m['id'] . '"' . ($chosen === $m['id'] ? ' selected' : '') . '>' . e($m['name']) . '</option>';
        }
        $html .= '</optgroup>';
    }

    return $html;
};
?>
<div class="overlay-field kind-<?= e($field['kind']) ?>" data-field="<?= e($name) ?>" data-kind="<?= e($field['kind']) ?>">
    <?php switch ($field['kind']):
        case 'text': ?>
            <label>
                <span><?= e($label) ?></span>
                <input type="text" name="<?= e($input) ?>" value="<?= e((string) $value) ?>" maxlength="<?= (int) ($field['max'] ?? 200) ?>">
                <?php if ($hint !== ''): ?><small class="muted"><?= e($hint) ?></small><?php endif; ?>
                <?php if (!empty($field['bot'])): ?>
                    <small class="bot-status <?= e($botStatus) ?>"><?= e(__('ui.overlay.bot_status_' . $botStatus)) ?>
                        <?php if ($botStatus === 'off'): ?><a href="<?= e(url('/bot')) ?>"><?= e(__('ui.nav.chat_bot')) ?></a><?php endif; ?></small>
                <?php endif; ?>
            </label>
            <?php break;
        case 'textarea': ?>
            <label>
                <span><?= e($label) ?></span>
                <textarea name="<?= e($input) ?>" rows="3" maxlength="<?= (int) ($field['max'] ?? 1000) ?>"><?= e((string) $value) ?></textarea>
            </label>
            <?php break;
        case 'number': ?>
            <label>
                <span><?= e($label) ?></span>
                <input type="number" name="<?= e($input) ?>" value="<?= e((string) $value) ?>" min="<?= e((string) $field['min']) ?>" max="<?= e((string) $field['max']) ?>" step="<?= e((string) ($field['step'] ?? 1)) ?>">
                <?php if ($hint !== ''): ?><small class="muted"><?= e($hint) ?></small><?php endif; ?>
            </label>
            <?php break;
        case 'color': ?>
            <label>
                <span><?= e($label) ?></span>
                <input type="color" name="<?= e($input) ?>" value="<?= e((string) $value) ?>">
            </label>
            <?php break;
        case 'select': ?>
            <label>
                <span><?= e($label) ?></span>
                <select name="<?= e($input) ?>">
                    <?php foreach ($field['options'] as $option): ?>
                        <option value="<?= e($option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= e(__('ui.overlay_option.' . $name . '_' . $option)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($hint !== ''): ?><small class="muted"><?= e($hint) ?></small><?php endif; ?>
            </label>
            <?php break;
        case 'toggle': ?>
            <input type="hidden" name="<?= e($input) ?>" value="0">
            <label class="inline">
                <input type="checkbox" name="<?= e($input) ?>" value="1" <?= $value ? 'checked' : '' ?>>
                <span><?= e($label) ?></span>
            </label>
            <?php break;
        case 'command': ?>
            <label>
                <span><?= e($label) ?></span>
                <span class="input-prefix"><span>!</span>
                    <input type="text" name="<?= e($input) ?>" value="<?= e((string) $value) ?>" maxlength="25" pattern="[A-Za-z0-9_]{0,25}" autocomplete="off">
                </span>
                <small class="muted"><?= e(__('ui.overlay.hint_command')) ?></small>
            </label>
            <?php break;
        case 'list': ?>
            <label>
                <span><?= e($label) ?></span>
                <input type="text" name="<?= e($input) ?>" value="<?= e(implode(', ', array_map('strval', (array) $value))) ?>">
                <small class="muted"><?= e($hint !== '' ? $hint . ' ' : '') ?><?= e(__('ui.overlay_ini.takes_list_' . ($field['items'] ?? 'text'))) ?></small>
            </label>
            <?php break;
        case 'roles': ?>
            <fieldset class="overlay-roles">
                <legend><?= e($label) ?></legend>
                <input type="hidden" name="<?= e($input) ?>[]" value="">
                <?php foreach (OverlayTypes::ROLES as $role): ?>
                    <label class="inline">
                        <input type="checkbox" name="<?= e($input) ?>[]" value="<?= e($role) ?>" <?= in_array($role, (array) $value, true) ? 'checked' : '' ?>>
                        <span><?= e(__('ui.overlay_role.' . $role)) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <?php break;
        case 'sound': ?>
            <?= View::partial('overlays/_sound', ['input' => $input, 'value' => $value, 'sounds' => $sounds, 'label' => $label]) ?>
            <?php if ($sounds === []): ?>
                <small class="muted"><?= e(__('ui.overlay.no_media_yet')) ?> <a href="<?= e(url('/overlays/media')) ?>"><?= e(__('ui.nav.media_library')) ?></a></small>
            <?php endif; ?>
            <?php break;
        case 'map':
            $rows = is_array($value) ? $value : [];
            $row = static function (string $index, string $login, array $rule) use ($input, $field, $sounds): string {
                ob_start(); ?>
                <div class="rule-row" data-rule-row>
                    <label class="rule-login">
                        <span><?= e(__('ui.overlay.rule_login')) ?></span>
                        <span class="input-prefix"><span>@</span>
                            <input type="text" name="<?= e($input . '[' . $index . '][login]') ?>" value="<?= e($login) ?>" maxlength="25" pattern="@?[A-Za-z0-9_]{1,25}" autocomplete="off">
                        </span>
                    </label>
                    <?php foreach ($field['simple'] as $sub):
                        $subInput = $input . '[' . $index . '][' . $sub . ']';
                        if ($field['fields'][$sub]['kind'] === 'sound'): ?>
                            <?= View::partial('overlays/_sound', ['input' => $subInput, 'value' => $rule[$sub] ?? null, 'sounds' => $sounds, 'label' => __('ui.overlay.rule_sound')]) ?>
                        <?php elseif ($field['fields'][$sub]['kind'] === 'toggle'): ?>
                            <label class="inline">
                                <input type="checkbox" name="<?= e($subInput) ?>" value="1" <?= !empty($rule[$sub]) ? 'checked' : '' ?>>
                                <span><?= e(__('ui.overlay.rule_' . $sub)) ?></span>
                            </label>
                        <?php endif;
                    endforeach; ?>
                    <button type="button" class="btn small danger-btn rule-remove" data-rule-remove><?= e(__('ui.action.remove')) ?></button>
                </div>
                <?php return (string) ob_get_clean();
            }; ?>
            <fieldset class="overlay-rules" data-rules data-next="<?= count($rows) ?>">
                <legend><?= e(__('ui.overlay.rules_' . $type . '_' . $name)) ?></legend>
                <small class="muted"><?= e(__('ui.overlay.rules_hint_' . $type . '_' . $name)) ?></small>
                <input type="hidden" name="<?= e($input) ?>[]" value="">
                <div class="rule-rows" data-rule-rows>
                    <?php $i = 0; foreach ($rows as $login => $rule): ?>
                        <?= $row((string) $i++, (string) $login, is_array($rule) ? $rule : []) ?>
                    <?php endforeach; ?>
                </div>
                <template data-rule-template><?= $row('__i__', '', []) ?></template>
                <button type="button" class="btn small" data-rule-add>+ <?= e(__('ui.overlay.rule_add')) ?></button>
            </fieldset>
            <?php break;
        case 'image':
            $chosen = is_array($value) ? (int) $value['asset'] : null; ?>
            <div class="overlay-media-pick">
                <label>
                    <span><?= e($label) ?></span>
                    <select name="<?= e($input) ?>[asset]" data-image-asset>
                        <option value=""><?= e(__('ui.overlay.no_image')) ?></option>
                        <?= $mediaOptions($images, $chosen) ?>
                    </select>
                </label>
                <img class="overlay-thumb" alt="" data-image-thumb hidden>
            </div>
            <?php if ($images === []): ?>
                <small class="muted"><?= e(__('ui.overlay.no_media_yet')) ?> <a href="<?= e(url('/overlays/media')) ?>"><?= e(__('ui.nav.media_library')) ?></a></small>
            <?php endif; ?>
            <?php break;
    endswitch; ?>
</div>
