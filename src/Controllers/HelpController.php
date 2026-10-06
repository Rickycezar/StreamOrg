<?php
declare(strict_types=1);

/**
 * "How it works" pages for the dashboard, the key vault and content,
 * opened in their own small window like the vault security explainer:
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

    private static function show(string $page): void
    {
        echo View::partial('help', [
            'page'     => $page,
            'sections' => self::PAGES[$page],
            'user'     => Auth::user(),
        ]);
    }
}
