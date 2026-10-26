<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A game's hashtag guess: the name in CamelCase, only its first words when long. */
final class GameHashtagTest extends TestCase
{
    public function testTheWholeNameIsJoinedInCamelCase(): void
    {
        self::assertSame('#CastlevaniaBelmontsCurse', GameHashtag::suggest("Castlevania: Belmont's Curse"));
        self::assertSame('#BatmanArkhamAsylum', GameHashtag::suggest('Batman: Arkham Asylum'));
        self::assertSame('#EltaDefyAllGods', GameHashtag::suggest('Elta - Defy All Gods'));
    }

    public function testLongNamesKeepTheirFirstWords(): void
    {
        self::assertSame('#BatmanArkhamAsylumGame', GameHashtag::suggest('Batman: Arkham Asylum Game of the Year Edition'));
        self::assertSame('#InjusticeGodsAmongUs', GameHashtag::suggest('Injustice: Gods Among Us Ultimate Edition'));
        self::assertSame('#Supercalifragilisticexpialidocious', GameHashtag::suggest('Supercalifragilisticexpialidocious Adventures'));
    }

    public function testPlainNamesAreCamelCase(): void
    {
        self::assertSame('#HellboundInc', GameHashtag::suggest('HELLBOUND INC'));
        self::assertSame('#PokemonUnite', GameHashtag::suggest('Pokémon Unite™'));
        self::assertSame('#Dressmaker', GameHashtag::suggest('Dressmaker (2026)'));
        self::assertSame('#Injustice2', GameHashtag::suggest('Injustice 2'));
    }

    public function testTypedTagsAreTidiedOrRefused(): void
    {
        self::assertSame('#CastlevaniaBC', GameHashtag::fromInput(' CastlevaniaBC '));
        self::assertNull(GameHashtag::fromInput('two words'));
        self::assertNull(GameHashtag::fromInput(''));
    }
}
