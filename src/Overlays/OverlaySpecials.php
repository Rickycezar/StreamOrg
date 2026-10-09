<?php
declare(strict_types=1);

/**
 * Surprises built into particular channels' overlays: not settings, never
 * shown on any page, and sent only to the overlays of the channel they
 * belong to (with the state, as settings._special). A rule's sound is a
 * file in src/Overlays/special/, served at an address derived from the
 * application key, so it cannot be guessed or listed.
 */
final class OverlaySpecials
{
    private const RULES = [
        'ricky_cezar' => [
            'watch_streak' => [
                'b_suzuki' => [
                    'dedication' => 'SEM SOM, PORQUE ELE É MUITO CHATO',
                    'sound'      => ['file' => 'suzuki.mp3', 'start' => 0, 'duration' => 5, 'fade_out' => 2],
                ],
            ],
        ],
    ];

    /**
     * The rules for an overlay type in a channel, by viewer login, with
     * their sounds as addresses on the overlay's host; empty for every
     * other channel.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function for(string $type, ?string $channel): array
    {
        $out = [];

        foreach (self::RULES[strtolower((string) $channel)][$type] ?? [] as $login => $rule) {
            if (isset($rule['sound'])) {
                $sound = $rule['sound'];
                $rule['sound'] = [
                    'url'      => OverlayConfig::baseUrl() . '/overlay/special/' . self::token($sound['file']),
                    'start'    => (float) ($sound['start'] ?? 0),
                    'duration' => isset($sound['duration']) ? (float) $sound['duration'] : null,
                    'volume'   => (float) ($sound['volume'] ?? 1),
                    'fade_in'  => (float) ($sound['fade_in'] ?? 0),
                    'fade_out' => (float) ($sound['fade_out'] ?? 0),
                ];
            }

            $out[$login] = $rule;
        }

        return $out;
    }

    /** The file behind a special sound's address, or null. */
    public static function file(string $token): ?string
    {
        foreach (glob(__DIR__ . '/special/*.mp3') ?: [] as $path) {
            if (hash_equals(self::token(basename($path)), $token)) {
                return $path;
            }
        }

        return null;
    }

    private static function token(string $file): string
    {
        return substr(hash_hmac('sha256', 'overlay-special:' . $file, (string) Config::get('security.app_key', '')), 0, 32);
    }
}
