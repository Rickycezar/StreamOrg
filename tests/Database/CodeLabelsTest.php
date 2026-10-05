<?php
declare(strict_types=1);

/** Admin-entered labels fill in for database codes the language files do not know. */
final class CodeLabelsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CodeLabels::forget();
        $this->pdo->exec("INSERT INTO key_platforms (code) VALUES ('phpunit_site') ON CONFLICT (code) DO NOTHING");
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        CodeLabels::forget();
    }

    public function testCodeWithoutAnyLabelShowsTheKey(): void
    {
        self::assertSame('key_platform.phpunit_site', Lang::code('key_platform', 'phpunit_site', 'pt-BR'));
    }

    public function testStoredLabelIsUsedAndFallsBackToTheDefaultLocale(): void
    {
        CodeLabels::set('key_platform', 'phpunit_site', 'en', 'PHPUnit Site', null);

        self::assertSame('PHPUnit Site', Lang::code('key_platform', 'phpunit_site', 'en'));
        self::assertSame('PHPUnit Site', Lang::code('key_platform', 'phpunit_site', 'pt-BR'));

        CodeLabels::set('key_platform', 'phpunit_site', 'pt-BR', 'Site do PHPUnit', null);
        self::assertSame('Site do PHPUnit', Lang::code('key_platform', 'phpunit_site', 'pt-BR'));
        self::assertSame('Site do PHPUnit', Lang::group('key_platform', 'pt-BR')['phpunit_site']);
    }

    public function testLanguageFileLabelWins(): void
    {
        $fromFile = Lang::code('key_platform', 'keymailer', 'en');
        CodeLabels::set('key_platform', 'keymailer', 'en', 'Something else', null);

        self::assertSame($fromFile, Lang::code('key_platform', 'keymailer', 'en'));
    }

    public function testBlankRemovesTheStoredLabel(): void
    {
        CodeLabels::set('key_platform', 'phpunit_site', 'en', 'PHPUnit Site', null);
        CodeLabels::set('key_platform', 'phpunit_site', 'en', '  ', null);

        self::assertSame('key_platform.phpunit_site', Lang::code('key_platform', 'phpunit_site', 'en'));
    }

    public function testInterfaceStringsNeverComeFromTheDatabase(): void
    {
        self::assertNull(CodeLabels::get('ui', 'app_name', 'en'));
        $this->expectException(InvalidArgumentException::class);
        CodeLabels::set('ui', 'app_name', 'en', 'Hacked', null);
    }
}
