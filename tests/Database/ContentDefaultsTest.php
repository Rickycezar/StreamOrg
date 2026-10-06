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
        $rows = [['text' => '[STEAM DECK]'], ['text' => '  '], ['text' => '[PT-BR]', 'default' => '1'], ['text' => '[steam deck]', 'default' => '1']];

        self::assertSame(
            [['prefix' => '[STEAM DECK]', 'is_default' => false], ['prefix' => '[PT-BR]', 'is_default' => true]],
            ContentDefaults::prefixesFromInput($rows)
        );
    }

    public function testTooLongPrefixIsRefused(): void
    {
        $this->expectException(UserError::class);
        ContentDefaults::prefixesFromInput([['text' => str_repeat('x', 61)]]);
    }

    public function testSeveralDefaultsKeepTheirOrder(): void
    {
        $user = $this->createUser('phpunit_prefixes');

        ContentDefaults::save($user, 120, [
            ['prefix' => '[A]', 'is_default' => true],
            ['prefix' => '[B]', 'is_default' => true],
            ['prefix' => '[C]', 'is_default' => false],
        ]);

        self::assertSame(
            [['[A]', true], ['[B]', true], ['[C]', false]],
            array_map(static fn (array $p): array => [$p['prefix'], $p['is_default']], ContentDefaults::prefixes($user))
        );
    }
}
