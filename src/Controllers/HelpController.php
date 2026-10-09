<?php
declare(strict_types=1);

/**
 * "How it works" pages for the dashboard, the key vault, content, the
 * content defaults (prefixes and counters), giveaways, embargoes, collabs,
 * planning a collab together, and the stream tools (overlays, the media
 * library, the chat bot), opened in their own small window like the vault security explainer:
 * what the person can do there, in plain words, with small drawings.
 * The words live in the language files (ui.help.<page>.*).
 */
final class HelpController
{
    /** Page => its sections, each drawn with the illustration of the same name (views/help/art.php). */
    public const PAGES = [
        'dashboard' => ['today', 'pick', 'shortcuts', 'attention', 'week', 'schedule'],
        'keys'      => ['vault', 'add', 'hidden', 'status', 'dates', 'content', 'giveaways'],
        'content'   => ['what', 'calendar', 'resize', 'statuses', 'title', 'warnings', 'twitch'],
        'defaults'  => ['schedule', 'length', 'prefixes', 'counters', 'numbers', 'limit'],
        'giveaways' => ['setup', 'prizes', 'winners', 'claim', 'private', 'finish'],
        'embargoes' => ['what', 'kinds', 'several', 'release', 'warnings', 'uncovered'],
        'collabs'   => ['streamers', 'idea', 'cast', 'plan', 'together', 'title'],
        'together'  => ['invite', 'join', 'own', 'time', 'agree', 'leave'],
        'overlays'  => ['add', 'obs', 'settings', 'viewers', 'bot', 'advanced', 'link'],
        'media'     => ['upload', 'sprites', 'pick', 'shared'],
        'bot'       => ['add', 'access', 'commands', 'custom', 'timers', 'logs'],
    ];

    public static function dashboard(): void
    {
        self::show('dashboard');
    }

    public static function keys(): void
    {
        self::show('keys');
    }

    public static function content(): void
    {
        self::show('content');
    }

    public static function defaults(): void
    {
        self::show('defaults');
    }

    public static function giveaways(): void
    {
        self::show('giveaways');
    }

    public static function embargoes(): void
    {
        self::show('embargoes');
    }

    public static function collabs(): void
    {
        self::show('collabs');
    }

    public static function together(): void
    {
        self::show('together');
    }

    public static function overlays(): void
    {
        self::show('overlays');
    }

    public static function media(): void
    {
        self::show('media');
    }

    public static function bot(): void
    {
        self::show('bot');
    }

    private static function show(string $page): void
    {
        echo View::partial('help', [
            'page'     => $page,
            'sections' => self::PAGES[$page],
            'user'     => Auth::user(),
        ]);
    }
}
