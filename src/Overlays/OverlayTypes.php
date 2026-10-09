<?php
declare(strict_types=1);

/**
 * The kinds of overlay a streamer can add, each with its settings fields.
 * A field's kind says how it is edited and checked, and how its value
 * reaches the overlay:
 *
 *   text, textarea   words (max length)
 *   number           a number within min and max
 *   color            #rrggbb
 *   select           one of options
 *   toggle           on or off
 *   command          a chat command word, without the "!" ('' for none)
 *   list             several items (items: int, login, word or text)
 *   roles            who may use a command: broadcaster, mod, vip, subscriber, everyone
 *   sound            {asset, segment, volume, start, duration, fade_in, fade_out}
 *                    from the media library (start and duration override
 *                    the segment's)
 *   image            {asset} from the media library
 *   map              rules per target (a streak number, a viewer's login),
 *                    each with fields of its own; written as [blocks] in
 *                    advanced mode; the sub-fields listed as "simple" are
 *                    also edited in the simple form, one row per target
 *
 * Every field has an English name (its key in the settings and in the
 * advanced-mode text) and may have a Portuguese one ("pt") that the text
 * also accepts and that Portuguese speakers see. Fields marked "advanced"
 * appear only in advanced mode, fields marked "bot" are messages the chat
 * bot sends (see bot/src/overlays.js). Text defaults name a string under
 * ui.overlay (in the creator's language).
 *
 * Each type also names its test events (the Test buttons), whether it
 * reads chat, its size in OBS (the browser source the streamer adds; taken
 * from the sources these overlays were first made for), what it needs from StreamOrg
 * (lookup: channel details for shoutouts; emotes: the channel's id for
 * emote services) and its page's script and style
 * (public/assets/overlays/<type>.js and .css).
 */
final class OverlayTypes
{
    public const ROLES = ['broadcaster', 'mod', 'vip', 'subscriber', 'everyone'];

    /** Options of a sound beyond the file, with their Portuguese names in the advanced text. */
    public const SOUND_OPTIONS = [
        'sound'    => 'som',
        'part'     => 'trecho',
        'volume'   => 'volume',
        'start'    => 'inicio',
        'duration' => 'duracao',
        'fade_in'  => 'fade_in',
        'fade_out' => 'fade_out',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'alert' => [
                'chat'  => true,
                'size'  => [1920, 1080],
                'tests' => ['alert'],
                'fields' => [
                    'message'      => ['kind' => 'text', 'max' => 140, 'default' => 'alert_default_message', 'pt' => 'mensagem'],
                    'image'        => ['kind' => 'image', 'pt' => 'imagem'],
                    'sound'        => ['kind' => 'sound', 'pt' => 'som'],
                    'show_seconds' => ['kind' => 'number', 'min' => 1, 'max' => 60, 'step' => 1, 'default' => 6, 'pt' => 'tempo_na_tela'],
                    'position'     => ['kind' => 'select', 'options' => ['top', 'center', 'bottom'], 'default' => 'center', 'pt' => 'posicao'],
                    'accent'       => ['kind' => 'color', 'default' => '#9146ff', 'pt' => 'cor_da_borda'],
                    'text_color'   => ['kind' => 'color', 'default' => '#ffffff', 'pt' => 'cor_do_texto'],
                    'font_size'    => ['kind' => 'number', 'min' => 16, 'max' => 120, 'step' => 2, 'default' => 44, 'pt' => 'tamanho_do_texto'],
                    'command'      => ['kind' => 'command', 'default' => 'alerta', 'pt' => 'comando'],
                    'roles'        => ['kind' => 'roles', 'default' => ['broadcaster', 'mod'], 'pt' => 'quem_pode_usar'],
                    'bot_reply'    => ['kind' => 'text', 'max' => 300, 'pt' => 'resposta_do_bot', 'bot' => true],
                ],
            ],
            'shoutout' => [
                'chat'   => true,
                'lookup' => true,
                'size'   => [900, 300],
                'tests'  => ['shoutout'],
                'fields' => [
                    'commands'      => ['kind' => 'list', 'items' => 'word', 'max_items' => 10, 'default' => ['so', 's2', 'sh'], 'pt' => 'comandos'],
                    'roles'         => ['kind' => 'roles', 'default' => ['broadcaster', 'mod'], 'pt' => 'quem_pode_usar'],
                    'sound'         => ['kind' => 'sound', 'pt' => 'som'],
                    'show_seconds'  => ['kind' => 'number', 'min' => 3, 'max' => 60, 'step' => 1, 'default' => 13, 'pt' => 'tempo_na_tela'],
                    'queue_gap'     => ['kind' => 'number', 'min' => 0, 'max' => 30, 'step' => 0.5, 'default' => 2, 'pt' => 'intervalo_entre_alertas', 'advanced' => true],
                    'show_category' => ['kind' => 'toggle', 'default' => true, 'pt' => 'mostrar_categoria'],
                    'show_box_art'  => ['kind' => 'toggle', 'default' => true, 'pt' => 'mostrar_capa'],
                    'show_title'    => ['kind' => 'toggle', 'default' => true, 'pt' => 'mostrar_titulo'],
                    'bot_reply'     => ['kind' => 'text', 'max' => 300, 'default' => 'so_bot_reply', 'pt' => 'resposta_do_bot', 'bot' => true],
                    'no_category'   => ['kind' => 'text', 'max' => 60, 'default' => 'so_no_category', 'pt' => 'texto_sem_categoria', 'advanced' => true],
                    'colors'        => ['kind' => 'select', 'options' => ['auto', 'fixed'], 'default' => 'auto', 'pt' => 'cores'],
                    'bar_color'     => ['kind' => 'color', 'default' => '#26bddf', 'pt' => 'cor_da_barra'],
                    'blob1'         => ['kind' => 'color', 'default' => '#6400ff', 'pt' => 'cor_bolha_1'],
                    'blob2'         => ['kind' => 'color', 'default' => '#000553', 'pt' => 'cor_bolha_2'],
                    'blob3'         => ['kind' => 'color', 'default' => '#00ffff', 'pt' => 'cor_bolha_3'],
                    'text_color'    => ['kind' => 'color', 'default' => '#fefefe', 'pt' => 'cor_do_texto'],
                    'accent_color'  => ['kind' => 'color', 'default' => '#000553', 'pt' => 'cor_de_destaque'],
                    'hue_shift'     => ['kind' => 'number', 'min' => 0, 'max' => 360, 'step' => 1, 'default' => 50, 'pt' => 'deslocamento_de_cor', 'advanced' => true],
                    'saturation'    => ['kind' => 'number', 'min' => 0, 'max' => 100, 'step' => 1, 'default' => 35, 'pt' => 'saturacao', 'advanced' => true],
                    'lightness'     => ['kind' => 'number', 'min' => 0, 'max' => 100, 'step' => 1, 'default' => 15, 'pt' => 'luminosidade', 'advanced' => true],
                    'users'         => ['kind' => 'map', 'header' => ['user', 'usuario'], 'target' => 'login', 'simple' => ['sound'], 'fields' => [
                        'bar_color'    => ['kind' => 'color', 'pt' => 'cor_da_barra'],
                        'blob1'        => ['kind' => 'color', 'pt' => 'cor_bolha_1'],
                        'blob2'        => ['kind' => 'color', 'pt' => 'cor_bolha_2'],
                        'blob3'        => ['kind' => 'color', 'pt' => 'cor_bolha_3'],
                        'text_color'   => ['kind' => 'color', 'pt' => 'cor_do_texto'],
                        'accent_color' => ['kind' => 'color', 'pt' => 'cor_de_destaque'],
                        'sound'        => ['kind' => 'sound', 'pt' => 'som'],
                    ]],
                ],
            ],
            'watch_streak' => [
                'chat'   => true,
                'emotes' => true,
                'size'   => [800, 300],
                'tests'  => ['streak_small', 'streak_big'],
                'fields' => [
                    'show_seconds'         => ['kind' => 'number', 'min' => 1, 'max' => 600, 'step' => 0.5, 'default' => 11, 'pt' => 'tempo_na_tela'],
                    'show_seconds_big'     => ['kind' => 'number', 'min' => 1, 'max' => 600, 'step' => 0.5, 'default' => 15, 'pt' => 'tempo_na_tela_grande'],
                    'queue_gap'            => ['kind' => 'number', 'min' => 0, 'max' => 60, 'step' => 0.5, 'default' => 1, 'pt' => 'intervalo_entre_alertas', 'advanced' => true],
                    'music_fade'           => ['kind' => 'number', 'min' => 0, 'max' => 30, 'step' => 0.1, 'default' => 1.5, 'pt' => 'fade_da_musica', 'advanced' => true],
                    'sound_normal'         => ['kind' => 'sound', 'pt' => 'som_normal'],
                    'sound_big'            => ['kind' => 'sound', 'pt' => 'som_grande'],
                    'big_streaks'          => ['kind' => 'list', 'items' => 'int', 'max_items' => 50, 'default' => [10, 20, 30, 60, 100, 150, 200, 250], 'pt' => 'sequencias_grandes'],
                    'user_sounds_sub_only' => ['kind' => 'toggle', 'default' => false, 'pt' => 'som_so_para_inscritos', 'advanced' => true],
                    'show_message'         => ['kind' => 'toggle', 'default' => true, 'pt' => 'mostrar_mensagem'],
                    'bot_reply'            => ['kind' => 'text', 'max' => 300, 'pt' => 'resposta_do_bot', 'bot' => true],
                    'bot_reply_big'        => ['kind' => 'text', 'max' => 300, 'default' => 'streak_bot_reply_big', 'pt' => 'resposta_do_bot_grande', 'bot' => true],
                    'line_small'           => ['kind' => 'text', 'max' => 120, 'default' => 'streak_line_small', 'pt' => 'texto_normal'],
                    'line_big'             => ['kind' => 'text', 'max' => 120, 'default' => 'streak_line_big', 'pt' => 'texto_grande'],
                    'banner'               => ['kind' => 'text', 'max' => 40, 'default' => 'streak_banner', 'pt' => 'titulo_grande'],
                    'tape'                 => ['kind' => 'text', 'max' => 40, 'default' => 'streak_tape', 'pt' => 'faixa', 'advanced' => true],
                    'panel_color'          => ['kind' => 'color', 'default' => '#1c1c3c', 'pt' => 'cor_do_painel'],
                    'highlight_color'      => ['kind' => 'color', 'default' => '#f8d830', 'pt' => 'cor_de_destaque'],
                    'bottom'               => ['kind' => 'number', 'min' => 0, 'max' => 1000, 'step' => 4, 'default' => 48, 'pt' => 'distancia_do_fundo', 'advanced' => true],
                    'streaks'              => ['kind' => 'map', 'header' => ['streak', 'sequencia'], 'target' => 'int', 'fields' => [
                        'sound' => ['kind' => 'sound', 'pt' => 'som'],
                    ]],
                    'users'                => ['kind' => 'map', 'header' => ['user', 'usuario'], 'target' => 'login', 'simple' => ['sound', 'always'], 'fields' => [
                        'sound'      => ['kind' => 'sound', 'pt' => 'som'],
                        'always'     => ['kind' => 'toggle', 'pt' => 'sempre'],
                        'dedication' => ['kind' => 'text', 'max' => 60, 'pt' => 'dedicatoria'],
                    ]],
                ],
            ],
            'chat' => [
                'chat'   => true,
                'emotes' => true,
                'size'   => [600, 800],
                'tests'  => ['message', 'highlight', 'reward', 'sub', 'streak'],
                'fields' => [
                    'theme'           => ['kind' => 'select', 'options' => ['cards', 'outline'], 'default' => 'cards', 'pt' => 'tema'],
                    'align'           => ['kind' => 'select', 'options' => ['left', 'right'], 'default' => 'left', 'pt' => 'alinhamento'],
                    'font_size'       => ['kind' => 'number', 'min' => 10, 'max' => 60, 'step' => 1, 'default' => 22, 'pt' => 'tamanho_do_texto'],
                    'emote_size'      => ['kind' => 'number', 'min' => 16, 'max' => 112, 'step' => 2, 'default' => 32, 'pt' => 'tamanho_dos_emotes', 'advanced' => true],
                    'card_color'      => ['kind' => 'color', 'default' => '#0f0f12', 'pt' => 'cor_do_cartao'],
                    'text_color'      => ['kind' => 'color', 'default' => '#e0e0e0', 'pt' => 'cor_do_texto'],
                    'highlight_color' => ['kind' => 'color', 'default' => '#ffd700', 'pt' => 'cor_de_destaque', 'advanced' => true],
                    'reward_color'    => ['kind' => 'color', 'default' => '#00e5ff', 'pt' => 'cor_de_resgate', 'advanced' => true],
                    'show_badges'     => ['kind' => 'toggle', 'default' => false, 'pt' => 'mostrar_insignias'],
                    'max_messages'    => ['kind' => 'number', 'min' => 3, 'max' => 100, 'step' => 1, 'default' => 30, 'pt' => 'maximo_de_mensagens', 'advanced' => true],
                    'hide_after'      => ['kind' => 'number', 'min' => 0, 'max' => 3600, 'step' => 1, 'default' => 0, 'pt' => 'sumir_depois'],
                    'hide_known_bots' => ['kind' => 'toggle', 'default' => true, 'pt' => 'esconder_bots_conhecidos'],
                    'ignored_users'   => ['kind' => 'list', 'items' => 'login', 'max_items' => 100, 'default' => ['streamorg', 'streamelements', 'nightbot', 'streamlabs', 'pokemoncommunitygame', 'moobot', 'wizebot'], 'pt' => 'ignorar_usuarios'],
                    'show_highlights' => ['kind' => 'toggle', 'default' => true, 'pt' => 'mostrar_destaques'],
                    'show_rewards'    => ['kind' => 'toggle', 'default' => true, 'pt' => 'mostrar_resgates'],
                    'rewards'         => ['kind' => 'list', 'items' => 'text', 'max_items' => 50, 'default' => [], 'pt' => 'resgates'],
                    'show_subs'       => ['kind' => 'toggle', 'default' => true, 'pt' => 'mostrar_inscricoes'],
                    'show_cheers'     => ['kind' => 'toggle', 'default' => true, 'pt' => 'mostrar_bits'],
                    'show_streaks'    => ['kind' => 'toggle', 'default' => true, 'pt' => 'mostrar_sequencias'],
                    'show_gifs'       => ['kind' => 'toggle', 'default' => true, 'pt' => 'mostrar_gifs'],
                    'message_sound'   => ['kind' => 'sound', 'pt' => 'som_de_mensagem'],
                    'text_highlight'  => ['kind' => 'text', 'max' => 120, 'default' => 'chat_text_highlight', 'pt' => 'texto_destaque', 'advanced' => true],
                    'text_reward'     => ['kind' => 'text', 'max' => 120, 'default' => 'chat_text_reward', 'pt' => 'texto_resgate', 'advanced' => true],
                    'text_sub'        => ['kind' => 'text', 'max' => 120, 'default' => 'chat_text_sub', 'pt' => 'texto_inscricao', 'advanced' => true],
                    'text_resub'      => ['kind' => 'text', 'max' => 120, 'default' => 'chat_text_resub', 'pt' => 'texto_renovacao', 'advanced' => true],
                    'text_cheer'      => ['kind' => 'text', 'max' => 120, 'default' => 'chat_text_cheer', 'pt' => 'texto_bits', 'advanced' => true],
                    'text_streak'     => ['kind' => 'text', 'max' => 120, 'default' => 'chat_text_streak', 'pt' => 'texto_sequencia', 'advanced' => true],
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /**
     * A new overlay's settings: every field's default (words in the
     * creator's language).
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $type): array
    {
        return self::defaultsOf((array) (self::get($type)['fields'] ?? []));
    }

    /**
     * Settings from the form or the advanced text, checked field by field:
     * unknown fields are dropped, values kept within bounds, media must be
     * the user's own or shared.
     *
     * @return array<string, mixed>
     */
    public static function clean(string $type, array $in, int $userId): array
    {
        return self::cleanFields((array) (self::get($type)['fields'] ?? []), $in, $userId);
    }

    /**
     * Settings as the overlay receives them: media turned into addresses
     * on the overlay's own host (a sound with its start, duration and
     * fades), and media that no longer exists left out. Rules (maps) are
     * resolved the same way.
     *
     * @return array<string, mixed>
     */
    public static function resolve(string $type, array $settings, int $userId): array
    {
        return self::resolveFields((array) (self::get($type)['fields'] ?? []), $settings, $userId);
    }

    /** One value checked against its field; $fallback when it does not fit. */
    public static function cleanValue(array $field, mixed $value, mixed $fallback, int $userId): mixed
    {
        $scalar = is_scalar($value) ? (string) $value : '';

        return match ($field['kind']) {
            'text'     => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $scalar)), 0, (int) ($field['max'] ?? 200)),
            'textarea' => mb_substr(trim(str_replace("\r\n", "\n", $scalar)), 0, (int) ($field['max'] ?? 1000)),
            'number'   => is_numeric($value) ? max($field['min'], min($field['max'], $value + 0)) : $fallback,
            'color'    => is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : $fallback,
            'select'   => in_array($value, $field['options'], true) ? $value : $fallback,
            'toggle'   => is_bool($value) ? $value : !in_array($scalar, ['', '0'], true),
            'command'  => self::word($scalar),
            'list'     => self::cleanList($field, $value),
            'roles'    => array_values(array_intersect(self::ROLES, array_map('strval', (array) $value))),
            'sound'    => self::cleanSound($value, $userId),
            'image'    => self::cleanImage($value, $userId),
            'map'      => self::cleanMap($field, $value, $userId),
            default    => $fallback,
        };
    }

    /** @return array<string, mixed> */
    private static function defaultsOf(array $fields): array
    {
        $out = [];

        foreach ($fields as $name => $field) {
            $default = $field['default'] ?? null;
            $out[$name] = match ($field['kind']) {
                'text', 'textarea' => is_string($default) ? __('ui.overlay.' . $default) : '',
                'sound', 'image'   => null,
                'list', 'roles'    => (array) $default,
                'map'              => [],
                'toggle'           => (bool) $default,
                default            => $default,
            };
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function cleanFields(array $fields, array $in, int $userId): array
    {
        $out = self::defaultsOf($fields);

        foreach ($fields as $name => $field) {
            if (array_key_exists($name, $in)) {
                $out[$name] = self::cleanValue($field, $in[$name], $out[$name], $userId);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function resolveFields(array $fields, array $settings, int $userId): array
    {
        $out = $settings;

        foreach ($fields as $name => $field) {
            $value = $settings[$name] ?? null;

            if ($field['kind'] === 'map') {
                $out[$name] = [];

                foreach (is_array($value) ? $value : [] as $target => $rule) {
                    $out[$name][(string) $target] = self::resolveFields($field['fields'], is_array($rule) ? $rule : [], $userId);
                }

                if ($out[$name] === []) {
                    $out[$name] = new stdClass();
                }

                continue;
            }

            if (!array_key_exists($name, $settings) || ($field['kind'] !== 'sound' && $field['kind'] !== 'image')) {
                continue;
            }

            $asset = is_array($value) && isset($value['asset']) ? MediaLibrary::usable((int) $value['asset'], $userId) : null;

            if ($asset === null) {
                $out[$name] = null;
                continue;
            }

            $out[$name] = ['url' => OverlayConfig::baseUrl() . '/' . $asset['path'], 'name' => $asset['name']];

            if ($field['kind'] === 'sound') {
                $segment = null;

                foreach ($asset['segments'] as $s) {
                    if ($s['key'] === ($value['segment'] ?? null)) {
                        $segment = $s;
                    }
                }

                $start    = $value['start'] ?? null;
                $duration = $value['duration'] ?? null;

                $out[$name] += [
                    'start'    => (float) ($start ?? $segment['start'] ?? 0),
                    'duration' => $duration !== null ? (float) $duration : (isset($segment['duration']) ? (float) $segment['duration'] : null),
                    'volume'   => (float) ($value['volume'] ?? 1),
                    'fade_in'  => (float) ($value['fade_in'] ?? 0),
                    'fade_out' => (float) ($value['fade_out'] ?? 0),
                ];
            }
        }

        return $out;
    }

    private static function word(string $value): string
    {
        return strtolower(substr((string) preg_replace('/[^a-z0-9_]/i', '', ltrim(trim($value), '!')), 0, 25));
    }

    /** @return list<int|string> */
    private static function cleanList(array $field, mixed $value): array
    {
        $items = is_array($value) ? $value : (preg_split('/\s*[,;\n]\s*/u', is_scalar($value) ? (string) $value : '') ?: []);
        $out   = [];

        foreach ($items as $item) {
            $item = trim(is_scalar($item) ? (string) $item : '');
            $item = match ($field['items'] ?? 'text') {
                'int'   => preg_match('/^\d{1,6}$/', $item) && (int) $item > 0 ? (int) $item : null,
                'login' => preg_match('/^@?([a-z0-9_]{1,25})$/i', $item, $m) ? strtolower($m[1]) : null,
                'word'  => self::word($item) !== '' ? self::word($item) : null,
                default => $item !== '' ? mb_substr((string) preg_replace('/\s+/u', ' ', $item), 0, 60) : null,
            };

            if ($item !== null && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return array_slice($out, 0, (int) ($field['max_items'] ?? 50));
    }

    /** @return array<string, array<string, mixed>> */
    private static function cleanMap(array $field, mixed $value, int $userId): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $target => $rule) {
            $target = self::target($field, (string) $target);

            if ($target === null || !is_array($rule) || count($out) >= 100) {
                continue;
            }

            $clean = [];

            foreach ($field['fields'] as $name => $sub) {
                if (array_key_exists($name, $rule)) {
                    $clean[$name] = self::cleanValue($sub, $rule[$name], null, $userId);
                }
            }

            $out[$target] = $clean;
        }

        return $out;
    }

    /** A map's target as it is stored (a positive number, or a lowercase login), or null. */
    public static function target(array $field, string $target): ?string
    {
        $target = strtolower(trim($target));

        return $field['target'] === 'int'
            ? (preg_match('/^\d{1,6}$/', $target) && (int) $target > 0 ? (string) (int) $target : null)
            : (preg_match('/^@?([a-z0-9_]{1,25})$/', $target, $m) ? $m[1] : null);
    }

    /** @return array{asset:int, segment:?string, volume:float, start:?float, duration:?float, fade_in:float, fade_out:float}|null */
    private static function cleanSound(mixed $value, int $userId): ?array
    {
        $asset = is_array($value) ? MediaLibrary::usable((int) ($value['asset'] ?? 0), $userId) : null;

        if ($asset === null || $asset['kind'] !== 'sound') {
            return null;
        }

        $segment = (string) ($value['segment'] ?? '');
        $seconds = static fn (mixed $v): ?float => is_numeric($v) ? max(0.0, min(600.0, round((float) $v, 3))) : null;

        return [
            'asset'    => $asset['id'],
            'segment'  => in_array($segment, array_column($asset['segments'], 'key'), true) ? $segment : null,
            'volume'   => max(0.0, min(1.0, round((float) ($value['volume'] ?? 1), 2))),
            'start'    => $seconds($value['start'] ?? null),
            'duration' => $seconds($value['duration'] ?? null),
            'fade_in'  => $seconds($value['fade_in'] ?? null) ?? 0.0,
            'fade_out' => $seconds($value['fade_out'] ?? null) ?? 0.0,
        ];
    }

    /** @return array{asset:int}|null */
    private static function cleanImage(mixed $value, int $userId): ?array
    {
        $asset = is_array($value) ? MediaLibrary::usable((int) ($value['asset'] ?? 0), $userId) : null;

        return $asset !== null && $asset['kind'] === 'image' ? ['asset' => $asset['id']] : null;
    }
}
