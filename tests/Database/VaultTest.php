<?php
declare(strict_types=1);

final class VaultTest extends DatabaseTestCase
{
    public function testManagedVaultEncryptsAndDecrypts(): void
    {
        $user = $this->createUser();
        $stored = Vault::encryptCode($user, 'AAAA-BBBB-CCCC');

        self::assertTrue(Vault::isEncrypted($stored));
        self::assertStringNotContainsString('AAAA', $stored);

        $this->newRequest();
        self::assertSame('AAAA-BBBB-CCCC', Vault::decryptCode($user, $stored));
    }

    public function testHashIgnoresSurroundingSpaceButNotCase(): void
    {
        $user = $this->createUser();

        self::assertSame(Vault::hashCode($user, 'ABC-123'), Vault::hashCode($user, '  ABC-123 '));
        self::assertNotSame(Vault::hashCode($user, 'ABC-123'), Vault::hashCode($user, 'abc-123'));
    }

    public function testCiphertextIsBoundToItsOwner(): void
    {
        $alice = $this->createUser('phpunit_alice');
        $bob   = $this->createUser('phpunit_bob');
        $stored = Vault::encryptCode($alice, 'SECRET-1');

        self::assertNull(Vault::decryptCode($bob, $stored));
        self::assertNotSame(Vault::hashCode($alice, 'SECRET-1'), Vault::hashCode($bob, 'SECRET-1'));
    }

    public function testPrivateVaultNeedsThePassword(): void
    {
        $user   = $this->createUser();
        $stored = Vault::encryptCode($user, 'PRIVATE-1');

        Vault::makePrivate($user, 'first-password');
        self::assertSame('private', Vault::mode($user));

        $_SESSION = [];
        $this->newRequest();
        self::assertFalse(Vault::isUnlocked($user));

        self::assertFalse(Vault::unlock($user, 'wrong'));
        self::assertTrue(Vault::unlock($user, 'first-password'));
        self::assertSame('PRIVATE-1', Vault::decryptCode($user, $stored));
    }

    public function testPasswordChangeRewrapsWithoutReencrypting(): void
    {
        $user   = $this->createUser();
        $stored = Vault::encryptCode($user, 'KEEP-ME');
        Vault::makePrivate($user, 'old-password');

        self::assertTrue(Vault::changePassword($user, 'old-password', 'new-password'));

        $this->newRequest();
        self::assertFalse(Vault::unlock($user, 'old-password'));
        self::assertTrue(Vault::unlock($user, 'new-password'));
        self::assertSame('KEEP-ME', Vault::decryptCode($user, $stored));
    }

    public function testBackToManagedKeepsTheSameKey(): void
    {
        $user   = $this->createUser();
        $stored = Vault::encryptCode($user, 'ROUND-TRIP');
        Vault::makePrivate($user, 'pw');

        self::assertTrue(Vault::makeManaged($user, 'pw'));

        $this->newRequest();
        $_SESSION = [];
        self::assertSame('managed', Vault::mode($user));
        self::assertSame('ROUND-TRIP', Vault::decryptCode($user, $stored));
    }

    public function testAdminResetOfPrivateVaultMakesOldCodesUnreadable(): void
    {
        $user   = $this->createUser();
        $stored = Vault::encryptCode($user, 'GONE');
        Vault::makePrivate($user, 'forgotten');

        Vault::resetPrivate($user, 'admin-set');

        $this->newRequest();
        self::assertTrue(Vault::unlock($user, 'admin-set'));
        self::assertNull(Vault::decryptCode($user, $stored));
    }
}
