<?php
declare(strict_types=1);

/**
 * Renders a plain-PHP template from views/ inside views/layout.php.
 */
final class View
{
    public static function render(string $template, array $data = [], ?string $title = null): void
    {
        $content = self::capture($template, $data);
        $pageTitle = $title;

        require dirname(__DIR__) . '/views/layout.php';
    }

    /**
     * Renders a page for the general public (claim links, viewers' prizes,
     * the privacy policy) in the landing page's look, without the app's menu.
     */
    public static function publicPage(string $template, array $data = [], ?string $title = null): void
    {
        $content   = self::capture($template, $data);
        $pageTitle = $title;

        require dirname(__DIR__) . '/views/public_layout.php';
    }

    /** Renders without the surrounding layout — used for AJAX fragments. */
    public static function partial(string $template, array $data = []): string
    {
        return self::capture($template, $data);
    }

    private static function capture(string $template, array $data): string
    {
        $path = dirname(__DIR__) . '/views/' . $template . '.php';

        if (!is_file($path)) {
            throw new RuntimeException("View not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $path;

        return (string) ob_get_clean();
    }
}
