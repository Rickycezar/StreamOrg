<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Which language a request gets (Lang::resolve) and per-domain defaults. */
final class LangTest extends TestCase
{
    private const DOMAINS = ['streamorg.com.br' => 'pt-BR', 'streamorg.com' => 'en'];

    public static function hosts(): array
    {
        return [
            'Brazilian domain'          => ['streamorg.com.br', 'pt-BR'],
            'international domain'      => ['streamorg.com', 'en'],
            'www. counts too'           => ['www.streamorg.com.br', 'pt-BR'],
            'port is ignored'           => ['streamorg.com.br:443', 'pt-BR'],
            'case is ignored'           => ['StreamOrg.COM', 'en'],
            'look-alike is no match'    => ['evilstreamorg.com', null],
            'unrelated host'            => ['localhost:8443', null],
        ];
    }

    #[DataProvider('hosts')]
    public function testForHost(string $host, ?string $expected): void
    {
        self::assertSame($expected, Lang::forHost($host, self::DOMAINS));
    }

    public function testUserSettingWinsOverEverything(): void
    {
        self::assertSame('en', Lang::resolve('en', 'pt-BR', 'streamorg.com.br', 'pt-BR', self::DOMAINS, 'en'));
    }

    public function testVisitorChoiceWinsOverDomain(): void
    {
        self::assertSame('en', Lang::resolve(null, 'en', 'streamorg.com.br', 'pt-BR', self::DOMAINS, 'en'));
    }

    public function testDomainWinsOverBrowser(): void
    {
        self::assertSame('pt-BR', Lang::resolve(null, null, 'streamorg.com.br', 'en-US,en;q=0.9', self::DOMAINS, 'en'));
        self::assertSame('en', Lang::resolve(null, null, 'streamorg.com', 'pt-BR,pt;q=0.9', self::DOMAINS, 'en'));
    }

    public function testBrowserDecidesElsewhere(): void
    {
        self::assertSame('pt-BR', Lang::resolve(null, null, 'localhost', 'pt-PT,pt;q=0.9', self::DOMAINS, 'en'));
        self::assertSame('en', Lang::resolve(null, null, 'localhost', 'de-DE', self::DOMAINS, 'en'));
    }

    public function testUnknownChoicesAreIgnored(): void
    {
        self::assertSame('pt-BR', Lang::resolve('xx', '../../etc', 'streamorg.com.br', '', self::DOMAINS, 'en'));
    }

    public function testAFixedDomainKeepsItsLanguageWhateverTheChoices(): void
    {
        $fixed = ['streamorg.com.br'];

        self::assertSame('pt-BR', Lang::resolve('en', 'en', 'streamorg.com.br', 'en-US', self::DOMAINS, 'en', $fixed));
        self::assertSame('pt-BR', Lang::resolve('en', null, 'www.streamorg.com.br', '', self::DOMAINS, 'en', $fixed));
        self::assertSame('pt-BR', Lang::resolve(null, 'pt-BR', 'streamorg.com', '', self::DOMAINS, 'en', $fixed), 'Other domains still switch.');
        self::assertSame('pt-BR', Lang::fixedFor('streamorg.com.br', self::DOMAINS, $fixed));
        self::assertNull(Lang::fixedFor('streamorg.com', self::DOMAINS, $fixed));
        self::assertNull(Lang::fixedFor('streamorg.com.br', self::DOMAINS, []));
    }

    public function testLinksForOthersGoToTheDomainOfTheirLanguage(): void
    {
        self::assertSame('https://streamorg.com.br', Lang::baseUrlFor('pt-BR', self::DOMAINS, [], 'https://streamorg.com'));
        self::assertSame('https://streamorg.com', Lang::baseUrlFor('en', self::DOMAINS, ['streamorg.com.br'], 'https://streamorg.com'));
        self::assertSame('https://base.example', Lang::baseUrlFor('de', self::DOMAINS, [], 'https://base.example/'));
        self::assertSame('https://base.example', Lang::baseUrlFor(null, [], [], 'https://base.example'));
    }

    public function testTheSwitcherComesBackToTheSamePageWithItsQuery(): void
    {
        $saved = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/claim?t=abc_DEF-123';

        try {
            self::assertSame('/claim?t=abc_DEF-123', LocaleController::here());
            parse_str((string) parse_url(LocaleController::links()['en']['href'], PHP_URL_QUERY), $query);
            self::assertSame('/claim?t=abc_DEF-123', $query['back']);
        } finally {
            $_SERVER['REQUEST_URI'] = $saved;
        }
    }
}
