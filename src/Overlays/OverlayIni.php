<?php
declare(strict_types=1);

/**
 * Advanced mode's settings text: an overlay's settings written as lines a
 * person edits by hand, read back forgivingly.
 *
 *   # a comment                      ignored (also after a value: "x = 1  # note")
 *   key = value                      also "key: value"; keys ignore case,
 *                                    accents, spaces and dashes, in English
 *                                    or Portuguese ("tempo na tela")
 *   [sound normal]                   the options of a sound field: sound,
 *   volume = 80%                     part, volume, start, duration, fade_in,
 *   fade_out = 2                     fade_out (Portuguese names too)
 *   [streak 100] / [user fulano]     a rule for a target, with its own fields
 *
 * Numbers take a comma or a point; yes/no also as sim/não, on/off, 1/0;
 * volumes 0 to 1 or a percentage; media by name, or "#id" when names
 * repeat. Lines that do not fit are skipped with a warning naming the line,
 * and the setting keeps its default, so a typo never stops an overlay.
 * Settings that are not written take their defaults.
 */
final class OverlayIni
{
    public const MAX_LENGTH = 20000;

    private const YES = ['yes', 'y', 'sim', 's', 'true', 'verdadeiro', 'on', 'ligado', 'ativado', '1'];
    private const NO  = ['no', 'n', 'nao', 'false', 'falso', 'off', 'desligado', 'desativado', '0'];

    /**
     * Reads a settings text: the settings it gives (not yet cleaned; see
     * OverlayTypes::clean) and what was skipped.
     *
     * @return array{0: array<string, mixed>, 1: list<array{line:int, message:string}>}
     */
    public static function parse(string $type, string $text, int $userId): array
    {
        $fields   = (array) (OverlayTypes::get($type)['fields'] ?? []);
        $names    = self::names($fields);
        $settings = [];
        $warnings = [];
        $section  = null;
        $warn     = static function (int $line, string $key, string ...$args) use (&$warnings): void {
            $warnings[] = ['line' => $line, 'message' => vsprintf(__('ui.overlay_ini.' . $key), $args)];
        };

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $warn(0, 'too_long', (string) self::MAX_LENGTH);
            $text = mb_substr($text, 0, self::MAX_LENGTH);
        }

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $raw) {
            $no   = $index + 1;
            $line = trim((string) preg_replace('/\s+#(\s.*)?$/u', '', $raw));

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//') || str_starts_with($line, ';')) {
                continue;
            }

            if (preg_match('/^\[\s*(.*?)\s*\]$/u', $line, $m)) {
                $section = self::section($fields, $names, $m[1]);

                if ($section === null) {
                    $warn($no, 'unknown_block', $m[1]);
                    $section = ['kind' => 'ignored'];
                } elseif ($section['kind'] === 'bad_target') {
                    $warn($no, 'bad_target', $m[1]);
                    $section = ['kind' => 'ignored'];
                } elseif ($section['kind'] === 'rule') {
                    $settings[$section['field']][$section['target']] ??= [];
                }

                continue;
            }

            $at = strcspn($line, '=:');

            if ($at === 0 || $at === strlen($line)) {
                $warn($no, 'not_a_setting', mb_substr($line, 0, 60));
                continue;
            }

            $key   = self::key(substr($line, 0, $at));
            $value = self::unquote(substr($line, $at + 1));

            if ($section !== null && $section['kind'] === 'ignored' && !isset($names[$key])) {
                continue;
            }

            if ($section !== null && $section['kind'] === 'sound' && ($option = self::soundOption($key)) !== null) {
                $settings[$section['field']] = self::soundWith($settings[$section['field']] ?? null, $option, $value, $userId, $no, $warn);
                continue;
            }

            if ($section !== null && $section['kind'] === 'rule') {
                $ruleFields = $fields[$section['field']]['fields'];
                $ruleNames  = self::names($ruleFields);
                $rule       = &$settings[$section['field']][$section['target']];

                if (isset($ruleNames[$key])) {
                    $name = $ruleNames[$key];
                    $parsed = self::value($name, $ruleFields[$name], $value, $rule[$name] ?? null, $userId, $no, $warn);

                    if ($parsed !== null) {
                        $rule[$name] = $parsed;
                    }

                    unset($rule);
                    continue;
                }

                if (($option = self::soundOption($key)) !== null && ($sound = self::firstSound($ruleFields)) !== null) {
                    $rule[$sound] = self::soundWith($rule[$sound] ?? null, $option, $value, $userId, $no, $warn);
                    unset($rule);
                    continue;
                }

                unset($rule);
            }

            if (in_array($key, ['canal', 'channel'], true)) {
                $warn($no, 'channel_from_account');
                continue;
            }

            if (!isset($names[$key]) || $fields[$names[$key]]['kind'] === 'map') {
                $warn($no, $section !== null && $section['kind'] !== 'ignored' ? 'unknown_in_block' : 'unknown_key', $key);
                continue;
            }

            $name   = $names[$key];
            $parsed = self::value($name, $fields[$name], $value, $settings[$name] ?? null, $userId, $no, $warn);

            if ($parsed !== null) {
                $settings[$name] = $parsed;
            }
        }

        return [$settings, $warnings];
    }

    /**
     * Writes settings as text, in the reader's language: an explanation,
     * then each setting with a comment saying what it does and what it
     * takes, the advanced ones after the others, then the blocks.
     */
    public static function render(string $type, array $settings, int $userId): string
    {
        $fields = (array) (OverlayTypes::get($type)['fields'] ?? []);
        $pt     = str_starts_with(Lang::locale(), 'pt');
        $media  = MediaLibrary::available($userId);
        $out    = [];
        $blocks = [];

        $rule = '# ' . str_repeat('=', 69);
        $out[] = $rule;
        $out[] = '#  ' . mb_strtoupper(__('ui.overlay_type.' . $type));
        $out[] = $rule;

        foreach (explode("\n", __('ui.overlay_ini.help')) as $help) {
            $out[] = '#  ' . $help;
        }

        $out[] = $rule;

        foreach ([false, true] as $advanced) {
            $out[] = '';
            $out[] = '';
            $out[] = '# ' . str_pad(' ' . __($advanced ? 'ui.overlay_ini.heading_advanced' : 'ui.overlay_ini.heading_main') . ' ', 40, '-', STR_PAD_BOTH);

            foreach ($fields as $name => $field) {
                if ($field['kind'] === 'map' || !empty($field['advanced']) !== $advanced) {
                    continue;
                }

                $key   = $pt && isset($field['pt']) ? $field['pt'] : $name;
                $value = $settings[$name] ?? null;
                $out[] = '';

                foreach (self::describe($type, $name, $field, $pt) as $comment) {
                    $out[] = '# ' . $comment;
                }

                $out[] = $key . ' = ' . self::show($field, $value, $media, $pt);

                if ($field['kind'] === 'sound' && is_array($value) && ($options = self::soundLines($value, $media, $pt, false)) !== []) {
                    $blocks[] = '';
                    $blocks[] = '[' . str_replace('_', ' ', $key) . ']';
                    array_push($blocks, ...$options);
                }
            }
        }

        if ($blocks !== []) {
            $out[] = '';
            $out[] = '';
            $out[] = '# ' . str_pad(' ' . __('ui.overlay_ini.heading_sounds') . ' ', 40, '-', STR_PAD_BOTH);
            array_push($out, ...$blocks);
        }

        foreach ($fields as $name => $field) {
            if ($field['kind'] !== 'map') {
                continue;
            }

            $header = $pt ? $field['header'][1] : $field['header'][0];
            $out[] = '';
            $out[] = '';
            $out[] = '# ' . str_pad(' ' . __('ui.overlay_ini.' . $type . '_' . $name . '_title') . ' ', 40, '-', STR_PAD_BOTH);

            foreach (explode("\n", __('ui.overlay_ini.' . $type . '_' . $name . '_help')) as $help) {
                $out[] = '# ' . $help;
            }

            $rules = is_array($settings[$name] ?? null) ? $settings[$name] : [];

            if ($rules === []) {
                $out[] = '#';
                $out[] = '# [' . $header . ' ' . ($field['target'] === 'int' ? '100' : ($pt ? 'fulano' : 'someone')) . ']';

                foreach ($field['fields'] as $sub => $subField) {
                    $out[] = '# ' . ($pt && isset($subField['pt']) ? $subField['pt'] : $sub) . ' = ' . self::example($subField, $pt);
                }
            }

            foreach ($rules as $target => $values) {
                $out[] = '';
                $out[] = '[' . $header . ' ' . $target . ']';

                foreach ($field['fields'] as $sub => $subField) {
                    if (!array_key_exists($sub, (array) $values) || $values[$sub] === null || $values[$sub] === '') {
                        continue;
                    }

                    $out[] = ($pt && isset($subField['pt']) ? $subField['pt'] : $sub) . ' = ' . self::show($subField, $values[$sub], $media, $pt);

                    if ($subField['kind'] === 'sound' && is_array($values[$sub])) {
                        array_push($out, ...self::soundLines($values[$sub], $media, $pt, false));
                    }
                }
            }
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * The words a field goes by in the text (English name, Portuguese name,
     * both normalized) to its name.
     *
     * @return array<string, string>
     */
    private static function names(array $fields): array
    {
        $out = [];

        foreach ($fields as $name => $field) {
            $out[self::key($name)] = $name;

            if (isset($field['pt'])) {
                $out[self::key($field['pt'])] = $name;
            }
        }

        return $out;
    }

    /** "Tempo na Tela", "tempo-na-tela" and "tempo_na_tela" all become "tempo_na_tela". */
    public static function key(string $text): string
    {
        $plain = (string) (class_exists(Normalizer::class)
            ? preg_replace('/\p{Mn}/u', '', (string) Normalizer::normalize($text, Normalizer::FORM_D))
            : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text));

        return (string) preg_replace('/[\s-]+/', '_', strtolower(trim($plain)));
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(["\'“‘])(.*)(["\'”’])$/u', $value, $m)) {
            return trim($m[2]);
        }

        return $value;
    }

    /**
     * What a [block] header refers to: a sound field's options, a rule for
     * a target, or null.
     *
     * @return array<string, string>|null
     */
    private static function section(array $fields, array $names, string $header): ?array
    {
        $key = self::key($header);

        if (isset($names[$key]) && $fields[$names[$key]]['kind'] === 'sound') {
            return ['kind' => 'sound', 'field' => $names[$key]];
        }

        if (!preg_match('/^(\S+)\s*[:#]?\s*(.+)$/u', trim($header), $m)) {
            return null;
        }

        $word = self::key($m[1]);

        foreach ($fields as $name => $field) {
            $words = array_map([self::class, 'key'], [...$field['header'] ?? [], ...(($field['target'] ?? '') === 'int' ? ['seq'] : ['usuaria'])]);

            if ($field['kind'] !== 'map' || !in_array($word, $words, true)) {
                continue;
            }

            $target = OverlayTypes::target($field, (string) preg_replace('~^(https?://)?(www\.)?twitch\.tv/~i', '', $m[2]));

            return $target === null ? ['kind' => 'bad_target'] : ['kind' => 'rule', 'field' => $name, 'target' => $target];
        }

        return null;
    }

    private static function soundOption(string $key): ?string
    {
        foreach (OverlayTypes::SOUND_OPTIONS as $en => $pt) {
            if ($key === self::key($en) || $key === self::key($pt) || ($en === 'sound' && $key === 'arquivo')) {
                return $en;
            }
        }

        return null;
    }

    private static function firstSound(array $fields): ?string
    {
        foreach ($fields as $name => $field) {
            if ($field['kind'] === 'sound') {
                return $name;
            }
        }

        return null;
    }

    /** A sound with one option set from the text. */
    private static function soundWith(mixed $sound, string $option, string $value, int $userId, int $no, callable $warn): ?array
    {
        $sound = is_array($sound) ? $sound : [];

        switch ($option) {
            case 'sound':
                $asset = self::asset($value, 'sound', $userId);

                if ($asset === null) {
                    if ($value !== '') {
                        $warn($no, 'media_missing', $value);
                    }

                    return $sound === [] ? null : $sound;
                }

                $sound['asset'] = $asset['id'];
                break;

            case 'part':
                $sound['part'] = $value;
                break;

            case 'volume':
                $volume = self::number($value);

                if ($volume !== null && (str_contains($value, '%') || ($volume > 1 && $volume <= 100))) {
                    $volume /= 100;
                }

                if ($volume === null || $volume < 0 || $volume > 1) {
                    $warn($no, 'bad_volume', $value);
                } else {
                    $sound['volume'] = $volume;
                }
                break;

            default:
                $seconds = self::number($value);

                if ($seconds === null || $seconds < 0 || $seconds > 600) {
                    $warn($no, 'bad_number', $value, '0', '600');
                } else {
                    $sound[$option] = $seconds;
                }
        }

        if (isset($sound['asset'], $sound['part'])) {
            $sound = self::withPart($sound, $userId, $no, $warn);
        }

        return $sound;
    }

    /** Turns a part's name into its segment key, once the sound is known. */
    private static function withPart(array $sound, int $userId, int $no, callable $warn): array
    {
        $asset = MediaLibrary::usable((int) $sound['asset'], $userId);
        $part  = self::key((string) $sound['part']);
        unset($sound['part']);

        if ($part === '' || $asset === null) {
            return $sound;
        }

        foreach ($asset['segments'] as $segment) {
            if (self::key($segment['name']) === $part || $segment['key'] === $part) {
                $sound['segment'] = $segment['key'];
                return $sound;
            }
        }

        $warn($no, 'part_missing', $part, $asset['name']);

        return $sound;
    }

    /** One setting's value read from text, or null (with a warning) when it does not fit. */
    private static function value(string $name, array $field, string $value, mixed $current, int $userId, int $no, callable $warn): mixed
    {
        switch ($field['kind']) {
            case 'number':
                $n = self::number($value);

                if ($n === null || $n < $field['min'] || $n > $field['max']) {
                    $warn($no, 'bad_number', $value, (string) $field['min'], (string) $field['max']);
                    return null;
                }

                return $n;

            case 'toggle':
                $word = self::key($value);

                if (in_array($word, self::YES, true)) {
                    return true;
                }

                if (in_array($word, self::NO, true)) {
                    return false;
                }

                $warn($no, 'bad_toggle', $value);
                return null;

            case 'color':
                if (preg_match('/^#?([0-9a-f])([0-9a-f])([0-9a-f])$/i', $value, $m)) {
                    return strtolower('#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3]);
                }

                if (preg_match('/^#?([0-9a-f]{6})$/i', $value, $m)) {
                    return strtolower('#' . $m[1]);
                }

                $warn($no, 'bad_color', $value);
                return null;

            case 'select':
                foreach ($field['options'] as $option) {
                    if (self::key($value) === self::key($option) || in_array(self::key($value), self::labels('ui.overlay_option.' . $name . '_' . $option), true)) {
                        return $option;
                    }
                }

                $warn($no, 'bad_option', $value, implode(', ', $field['options']));
                return null;

            case 'roles':
                $roles = [];

                foreach (preg_split('/\s*[,;]\s*|\s+e\s+|\s+and\s+/u', $value) ?: [] as $item) {
                    $found = null;

                    foreach (OverlayTypes::ROLES as $role) {
                        if (self::key($item) === $role || in_array(self::key($item), self::labels('ui.overlay_role.' . $role), true)) {
                            $found = $role;
                        }
                    }

                    if ($found === null && trim($item) !== '') {
                        $warn($no, 'bad_role', $item, implode(', ', OverlayTypes::ROLES));
                    } elseif ($found !== null) {
                        $roles[] = $found;
                    }
                }

                return $roles;

            case 'list':
                if (($field['items'] ?? '') === 'int') {
                    foreach (preg_split('/[\s,;]+/', $value) ?: [] as $item) {
                        if ($item !== '' && !preg_match('/^\d{1,6}$/', $item)) {
                            $warn($no, 'bad_list_item', $item);
                        }
                    }

                    return preg_split('/[\s,;]+/', $value);
                }

                return $value;

            case 'command':
                return ltrim($value, '!');

            case 'sound':
                if ($value === '') {
                    return false;
                }

                $asset = self::asset($value, 'sound', $userId);

                if ($asset === null) {
                    $warn($no, 'media_missing', $value);
                    return null;
                }

                return ['asset' => $asset['id']] + (is_array($current) ? $current : []);

            case 'image':
                if ($value === '') {
                    return false;
                }

                $asset = self::asset($value, 'image', $userId);

                if ($asset === null) {
                    $warn($no, 'media_missing', $value);
                    return null;
                }

                return ['asset' => $asset['id']];

            default:
                return $value;
        }
    }

    /** Words the option or role is called in every language, normalized. @return list<string> */
    private static function labels(string $key): array
    {
        $out = [];

        foreach (Lang::available() as $locale) {
            $label = Lang::t($key, (string) $locale);

            if ($label !== $key) {
                $out[] = self::key($label);
            }
        }

        return $out;
    }

    private static function number(string $value): ?float
    {
        return preg_match('/-?\d+(?:[.,]\d+)?/', $value, $m) ? (float) str_replace(',', '.', $m[0]) : null;
    }

    /**
     * A media file the user may use, by "#id" or by name.
     *
     * @return array<string, mixed>|null
     */
    private static function asset(string $ref, string $kind, int $userId): ?array
    {
        if (preg_match('/^#(\d+)\b/', trim($ref), $m)) {
            $asset = MediaLibrary::usable((int) $m[1], $userId);

            return $asset !== null && $asset['kind'] === $kind ? $asset : null;
        }

        $want = self::key((string) preg_replace('/\.(mp3|ogg|wav|png|jpe?g|gif|webp)$/i', '', $ref));

        foreach (MediaLibrary::available($userId, $kind) as $asset) {
            if (self::key($asset['name']) === $want) {
                return $asset;
            }
        }

        return null;
    }

    /** How a media file is written: its name, or "#id name" when another has the same name. */
    private static function mediaRef(int $id, array $media): string
    {
        $mine = null;
        $same = 0;

        foreach ($media as $asset) {
            if ($asset['id'] === $id) {
                $mine = $asset;
            }
        }

        if ($mine === null) {
            return '';
        }

        foreach ($media as $asset) {
            $same += (int) (self::key($asset['name']) === self::key($mine['name']));
        }

        return $same > 1 ? '#' . $id . ' ' . $mine['name'] : $mine['name'];
    }

    /** A value as text. */
    private static function show(array $field, mixed $value, array $media, bool $pt): string
    {
        return match ($field['kind']) {
            'toggle'        => $value ? ($pt ? 'sim' : 'yes') : ($pt ? 'não' : 'no'),
            'list', 'roles' => implode(', ', array_map('strval', (array) $value)),
            'number'        => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.'),
            'sound', 'image' => is_array($value) && isset($value['asset']) ? self::mediaRef((int) $value['asset'], $media) : '',
            default         => is_scalar($value) ? (string) $value : '',
        };
    }

    /** @return list<string> a sound's options beyond the file, one per line */
    private static function soundLines(array $sound, array $media, bool $pt, bool $withFile): array
    {
        $name  = static fn (string $en): string => $pt ? OverlayTypes::SOUND_OPTIONS[$en] : $en;
        $lines = [];

        if ($withFile) {
            $lines[] = $name('sound') . ' = ' . self::mediaRef((int) $sound['asset'], $media);
        }

        if (!empty($sound['segment'])) {
            foreach ($media as $asset) {
                foreach ($asset['id'] === (int) $sound['asset'] ? $asset['segments'] : [] as $segment) {
                    if ($segment['key'] === $sound['segment']) {
                        $lines[] = $name('part') . ' = ' . $segment['name'];
                    }
                }
            }
        }

        if (isset($sound['volume']) && (float) $sound['volume'] !== 1.0) {
            $lines[] = $name('volume') . ' = ' . round((float) $sound['volume'] * 100) . '%';
        }

        foreach (['start', 'duration', 'fade_in', 'fade_out'] as $option) {
            if (isset($sound[$option]) && (float) $sound[$option] > 0) {
                $lines[] = $name($option) . ' = ' . rtrim(rtrim(number_format((float) $sound[$option], 3, '.', ''), '0'), '.');
            }
        }

        return $lines;
    }

    /** @return list<string> the comment lines above a setting */
    private static function describe(string $type, string $name, array $field, bool $pt): array
    {
        $label = __('ui.overlay_field.' . $name);
        $hint  = Lang::t('ui.overlay_ini.' . $type . '_' . $name, Lang::locale());
        $lines = [$label . ($hint !== 'ui.overlay_ini.' . $type . '_' . $name ? ' — ' . $hint : '')];

        $takes = match ($field['kind']) {
            'number'  => sprintf(__('ui.overlay_ini.takes_number'), self::show($field, $field['min'], [], $pt), self::show($field, $field['max'], [], $pt)),
            'toggle'  => __('ui.overlay_ini.takes_toggle'),
            'color'   => __('ui.overlay_ini.takes_color'),
            'select'  => sprintf(__('ui.overlay_ini.takes_select'), implode(', ', array_map(
                static fn (string $o): string => $o . ' (' . mb_strtolower(__('ui.overlay_option.' . $name . '_' . $o)) . ')',
                $field['options']
            ))),
            'roles'   => sprintf(__('ui.overlay_ini.takes_roles'), implode(', ', OverlayTypes::ROLES)),
            'list'    => __('ui.overlay_ini.takes_list_' . ($field['items'] ?? 'text')),
            'sound'   => __('ui.overlay_ini.takes_sound'),
            'image'   => __('ui.overlay_ini.takes_image'),
            'command' => __('ui.overlay_ini.takes_command'),
            default   => null,
        };

        if ($takes !== null) {
            $lines[] = '  ' . $takes;
        }

        return $lines;
    }

    private static function example(array $field, bool $pt): string
    {
        return match ($field['kind']) {
            'sound'  => $pt ? 'nome_do_som' : 'sound_name',
            'color'  => '#ff4500',
            'toggle' => $pt ? 'sim' : 'yes',
            default  => $pt ? 'texto' : 'text',
        };
    }
}
