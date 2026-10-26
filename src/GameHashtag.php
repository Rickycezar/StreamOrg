<?php
declare(strict_types=1);

/**
 * A game's name as a hashtag, the way key sites such as Keymailer ask for
 * it in a title. Only a hashtag an administrator saved on the game
 * (#CastlevaniaBC) goes in titles; the guess (the name in CamelCase,
 * only its first words when long) is what administrators see to confirm
 * or change.
 */
final class GameHashtag
{
    public const PATTERN = '/^#[A-Za-z0-9_]{1,80}$/';

    /** A guess keeps whole words while it stays this long; a longer name keeps only its first words. */
    private const GUESS_MAX = 24;

    /** Small words a shortened guess does not end on. */
    private const JOINERS = ['a', 'an', 'and', 'of', 'the', 'to', 'in', 'on', 'for', 'e', 'o', 'de', 'do', 'da', 'dos', 'das'];

    public static function suggest(string $title): string
    {
        $title = (string) preg_replace('/\s*\((?:19|20)\d\d\)\s*$/', '', self::ascii($title));
        $words = self::words($title);

        $shouting = array_filter($words, static fn (string $w): bool => !ctype_upper($w) && !ctype_digit($w)) === [];
        $kept     = [];
        $length   = 0;

        foreach ($words as $word) {
            $piece = ctype_digit($word) || (!$shouting && ctype_upper($word)) ? $word : ucfirst(strtolower($word));

            if ($kept !== [] && $length + strlen($piece) > self::GUESS_MAX) {
                while (count($kept) > 1 && in_array(strtolower(end($kept)), self::JOINERS, true)) {
                    array_pop($kept);
                }
                break;
            }

            $kept[]  = $piece;
            $length += strlen($piece);
        }

        $tag = implode('', $kept);

        return $tag === '' ? '' : '#' . substr($tag, 0, 80);
    }

    /** A typed hashtag, tidied; null when it cannot be one. */
    public static function fromInput(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = '#' . ltrim($value, '#');

        return preg_match(self::PATTERN, $value) ? $value : null;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $text = str_replace(["'", '’'], '', $text);

        return array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $text) ?: [], static fn (string $w): bool => $w !== ''));
    }

    private static function ascii(string $text): string
    {
        $text = str_replace(['™', '®', '©'], '', $text);

        if (class_exists('Normalizer')) {
            $text = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($text, Normalizer::FORM_D));
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $ascii === false ? $text : $ascii;
    }
}
