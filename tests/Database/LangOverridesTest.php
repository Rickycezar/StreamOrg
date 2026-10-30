<?php
declare(strict_types=1);

/** Texts edited in Administration → Languages are laid over the language files and survive without touching them. */
final class LangOverridesTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Lang::reload();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Lang::reload();
    }

    public function testAnEditShowsAndClearingItBringsTheDefaultBack(): void
    {
        $default  = Lang::t('ui.nav.games', 'en');
        $defaults = LangFile::group(LangFile::load('en'), 'ui.nav');

        self::assertSame(1, LangOverrides::saveGroup('en', 'ui.nav', ['games' => 'PHPUnit games'], $defaults, null));
        Lang::reload();
        self::assertSame('PHPUnit games', Lang::t('ui.nav.games', 'en'));
        self::assertSame($default, LangFile::group(LangFile::load('en'), 'ui.nav')['games'], 'The file is not touched.');

        LangOverrides::saveGroup('en', 'ui.nav', ['games' => $default], $defaults, null);
        Lang::reload();
        self::assertSame($default, Lang::t('ui.nav.games', 'en'), 'Typing the default clears the edit.');

        LangOverrides::saveGroup('en', 'ui.nav', ['games' => 'Again'], $defaults, null);
        LangOverrides::saveGroup('en', 'ui.nav', ['games' => '   '], $defaults, null);
        Lang::reload();
        self::assertSame($default, Lang::t('ui.nav.games', 'en'), 'A blank clears it too.');
        self::assertSame(0, LangOverrides::count('en'));
    }

    public function testAnEditNeverReplacesAWholeGroup(): void
    {
        $this->pdo->exec("INSERT INTO lang_overrides (locale, path, value) VALUES ('en', 'ui.nav', 'broken')");
        Lang::reload();

        self::assertIsArray(Lang::group('ui.nav', 'en'));
        self::assertNotSame('ui.nav.games', Lang::t('ui.nav.games', 'en'));
    }
}
