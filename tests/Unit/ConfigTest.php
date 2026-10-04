<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Settings from environment variables, as a container platform provides them. */
final class ConfigTest extends TestCase
{
    private const VARS = ['DATABASE_URL', 'DB_HOST', 'DB_PASSWORD', 'APP_KEY', 'APP_ENV', 'APP_DEBUG',
                          'APP_BASE_URL', 'TRUSTED_PROXIES', 'CONTACT_EMAIL'];

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
    }

    private static function fromEnv(): array
    {
        return (new ReflectionMethod(Config::class, 'fromEnvironment'))->invoke(null);
    }

    public function testDatabaseUrlIsParsed(): void
    {
        putenv('DATABASE_URL=postgres://app%40user:p%3Ass%2Fw@db.internal:6543/streamorg_prod?sslmode=require');

        self::assertSame([
            'host'     => 'db.internal',
            'port'     => 6543,
            'name'     => 'streamorg_prod',
            'user'     => 'app@user',
            'password' => 'p:ss/w',
            'sslmode'  => 'require',
        ], self::fromEnv()['db']);
    }

    public function testSafeDefaults(): void
    {
        $config = self::fromEnv();

        self::assertSame('production', $config['app']['env']);
        self::assertFalse($config['app']['debug']);
        self::assertSame([], $config['app']['trusted_proxies']);
        self::assertSame('', $config['security']['app_key']);
    }

    public function testAppSettings(): void
    {
        putenv('APP_KEY=abc123');
        putenv('APP_DEBUG=true');
        putenv('APP_BASE_URL=https://streamorg.example.com');
        putenv('TRUSTED_PROXIES= 10.0.0.0/8, 172.16.0.0/12 ,');

        $config = self::fromEnv();

        self::assertSame('abc123', $config['security']['app_key']);
        self::assertTrue($config['app']['debug']);
        self::assertSame('https://streamorg.example.com', $config['app']['base_url']);
        self::assertSame(['10.0.0.0/8', '172.16.0.0/12'], $config['app']['trusted_proxies']);
    }

    public function testRejectsNonPostgresUrl(): void
    {
        putenv('DATABASE_URL=mysql://u:p@h/db');

        $this->expectException(RuntimeException::class);
        self::fromEnv();
    }
}
