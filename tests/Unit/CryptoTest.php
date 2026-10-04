<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $sealed = Crypto::encrypt('client-secret-123');

        self::assertTrue(Crypto::isEncrypted($sealed));
        self::assertNotSame('client-secret-123', $sealed);
        self::assertSame('client-secret-123', Crypto::decrypt($sealed));
    }

    public function testSameValueEncryptsDifferentlyEachTime(): void
    {
        self::assertNotSame(Crypto::encrypt('abc'), Crypto::encrypt('abc'));
    }

    public function testTamperedCiphertextFailsInsteadOfReturningGarbage(): void
    {
        $sealed = Crypto::encrypt('client-secret-123');
        $raw    = base64_decode(substr($sealed, strlen('enc:v1:')));
        $raw[30] = chr(ord($raw[30]) ^ 1);

        self::assertNull(Crypto::decrypt('enc:v1:' . base64_encode($raw)));
    }

    public function testPlainValuesPassThrough(): void
    {
        self::assertNull(Crypto::encrypt(null));
        self::assertSame('', Crypto::encrypt(''));
        self::assertSame('legacy-plain', Crypto::decrypt('legacy-plain'));
    }
}
