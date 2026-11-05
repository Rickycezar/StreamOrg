<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Game names: tidied, capped with "…" when a provider sends a runaway one, and shown clamped. */
final class GameTitleTest extends TestCase
{
    public function testRealLongTitlesAreKeptWhole(): void
    {
        $real = 'AVIÃOZINHO DO TRÁFICO PRÉ 4: SOBRARAM DEMONIOS DO PORTAL PRO INFERNO NA FAVELA QUE ABRI E AGORA TENHO QUE SALVAR OS MAMADAS DO RONALDO';

        self::assertSame($real, GameCatalog::title("  {$real}\n"));
        self::assertSame('Hades II', GameCatalog::title("Hades   II"));
    }

    public function testRunawayTitlesAreCut(): void
    {
        $title = GameCatalog::title(str_repeat('Ab ', 150));

        self::assertSame(GameCatalog::TITLE_MAX, mb_strlen($title));
        self::assertStringEndsWith('…', $title);
    }

    public function testTheDisplayKeepsTheWholeNameOnHover(): void
    {
        $html = game_name('<Long & Name>');

        self::assertStringContainsString('title="&lt;Long &amp; Name&gt;"', $html);
        self::assertStringContainsString('class="game-name"', $html);
        self::assertStringContainsString('game-name inline', game_name('x', true));
    }
}
