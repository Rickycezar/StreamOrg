<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Theme families resolve to the variant to paint first, for each mode. */
final class ThemesTest extends TestCase
{
    public function testModesPickTheRightVariant(): void
    {
        self::assertSame('sepia', Themes::forUser(['theme' => 'ember', 'theme_mode' => 'light'])['variant']);
        self::assertSame('ember', Themes::forUser(['theme' => 'ember', 'theme_mode' => 'dark'])['variant']);

        $auto = Themes::forUser(['theme' => 'harbour', 'theme_mode' => 'auto']);
        self::assertSame(['harbour-light', 'harbour-light', 'harbour', 'auto'], [$auto['variant'], $auto['light'], $auto['dark'], $auto['mode']]);
    }

    public function testUnknownOrMissingValuesFallBackToTheDefaults(): void
    {
        self::assertSame(['light', 'auto'], [Themes::forUser(null)['variant'], Themes::forUser(null)['mode']]);
        self::assertSame('light', Themes::forUser(['theme' => 'kelp', 'theme_mode' => 'sideways'])['variant']);
    }

    public function testEveryFamilyHasALightAndADarkVariant(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.css');

        foreach (Themes::FAMILIES as $family => $variants) {
            self::assertCount(2, $variants, $family);

            foreach ($variants as $i => $variant) {
                self::assertMatchesRegularExpression(
                    '/\[data-theme="' . preg_quote($variant, '/') . '"\] \{\s*color-scheme: ' . ($i === 0 ? 'light' : 'dark') . ';/',
                    $css,
                    "{$family}: {$variant}"
                );
            }
        }
    }
}
