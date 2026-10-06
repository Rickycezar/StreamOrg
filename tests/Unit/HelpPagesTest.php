<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Every "How it works" section has its words in every language, and its drawing. */
final class HelpPagesTest extends TestCase
{
    public function testEverySectionIsWrittenAndDrawn(): void
    {
        $art = (string) file_get_contents(dirname(__DIR__, 2) . '/views/help/art.php');

        foreach (Lang::available() as $locale) {
            foreach (HelpController::PAGES as $page => $sections) {
                foreach (['title', 'lead', 'tips'] as $key) {
                    $this->assertNotSame("ui.help.{$page}.{$key}", Lang::t("ui.help.{$page}.{$key}", $locale), "{$locale} {$page}.{$key}");
                }

                foreach ($sections as $section) {
                    foreach (['_title', '_text'] as $suffix) {
                        $key = "ui.help.{$page}.{$section}{$suffix}";
                        $this->assertNotSame($key, Lang::t($key, $locale), "{$locale} {$key}");
                    }

                    $this->assertStringContainsString("'{$page}.{$section}' =>", $art, "drawing for {$page}.{$section}");
                }
            }
        }
    }
}
