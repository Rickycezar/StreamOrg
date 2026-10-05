<?php
declare(strict_types=1);

/** Usual content length and title prefixes, one of them the default. */
final class ContentDefaultsTest extends DatabaseTestCase
{
    public function testLengthDefaultsToTwoHoursAndCanChange(): void
    {
        $user = $this->createUser('phpunit_lengths');

        self::assertSame(120, ContentDefaults::minutes($user));

        ContentDefaults::save($user, 90, []);
        self::assertSame(90, ContentDefaults::minutes($user));
    }

    public function testLengthInputMustBeAReasonableNumberOfMinutes(): void
    {
        self::assertSame(45, ContentDefaults::minutesFromInput(' 45 '));
        self::assertNull(ContentDefaults::minutesFromInput('10'));
        self::assertNull(ContentDefaults::minutesFromInput('1441'));
        self::assertNull(ContentDefaults::minutesFromInput('1:30'));
    }

    public function testPrefixesKeepOrderDropBlanksAndRepeats(): void
    {
        $rows = ['0' => ['text' => '[STEAM DECK]'], '1' => ['text' => '  '], 'n9' => ['text' => '[PT-BR]'], '2' => ['text' => '[steam deck]']];

        self::assertSame(
            [['prefix' => '[STEAM DECK]', 'is_default' => false], ['prefix' => '[PT-BR]', 'is_default' => true]],
            ContentDefaults::prefixesFromInput($rows, 'n9')
        );
    }

    public function testTooLongPrefixIsRefused(): void
    {
        self::assertNull(ContentDefaults::prefixesFromInput([['text' => str_repeat('x', 61)]], ''));
    }

    public function testOnlyOneDefaultIsKept(): void
    {
        $user = $this->createUser('phpunit_prefixes');

        ContentDefaults::save($user, 120, [
            ['prefix' => '[A]', 'is_default' => true],
            ['prefix' => '[B]', 'is_default' => true],
        ]);

        self::assertSame(
            [['prefix' => '[A]', 'is_default' => true], ['prefix' => '[B]', 'is_default' => false]],
            ContentDefaults::prefixes($user)
        );
    }
}
