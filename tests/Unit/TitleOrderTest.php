<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A title order keeps the known parts once each and puts back any that are missing. */
final class TitleOrderTest extends TestCase
{
    public function testAGivenOrderIsKeptAndCleaned(): void
    {
        self::assertSame(
            ['sponsor', 'prefix', 'text', 'suffix', 'collab', 'game', 'devpub'],
            ContentDefaults::titleOrderFrom('sponsor,prefix,text,suffix,collab,game,devpub')
        );
        self::assertSame(ContentDefaults::TITLE_PARTS, ContentDefaults::titleOrderFrom('nonsense,,text'));
    }

    public function testMissingPartsGoWhereTheyUsuallyAre(): void
    {
        self::assertSame(
            ['prefix', 'text', 'suffix', 'collab', 'game', 'devpub', 'sponsor'],
            ContentDefaults::titleOrderFrom(['prefix', 'text', 'suffix', 'collab', 'sponsor', 'sponsor'])
        );
    }
}
