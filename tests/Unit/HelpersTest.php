<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public static function proxies(): array
    {
        return [
            'local Caddy, HTTPS'        => [['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '192.168.0.20'], true, '192.168.0.20'],
            'IPv6 loopback proxy'       => [['REMOTE_ADDR' => '::1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '10.0.0.5, 127.0.0.1'], true, '10.0.0.5'],
            'forged from the network'   => [['REMOTE_ADDR' => '192.168.0.50', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], false, '192.168.0.50'],
            'direct, no proxy'          => [['REMOTE_ADDR' => '127.0.0.1'], false, '127.0.0.1'],
            'direct HTTPS'              => [['REMOTE_ADDR' => '10.1.1.1', 'HTTPS' => 'on'], true, '10.1.1.1'],
            'garbage forwarded address' => [['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip'], false, '127.0.0.1'],
            'client-forged left entry'  => [['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 203.0.113.9'], true, '203.0.113.9'],
            'container proxy, untrusted by default' => [['REMOTE_ADDR' => '10.0.3.4', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], false, '10.0.3.4'],
        ];
    }

    public function testContainerProxyTrustedThroughConfiguredRange(): void
    {
        $saved = Config::load();
        Config::reset(['app' => ['trusted_proxies' => ['10.0.0.0/8']]] + $saved);

        try {
            $_SERVER = ['REMOTE_ADDR' => '10.0.3.4', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.9.9'];

            self::assertTrue(request_is_https());
            self::assertSame('198.51.100.7', client_ip());
        } finally {
            Config::reset($saved);
        }
    }

    public function testIpRanges(): void
    {
        self::assertTrue(ip_in_ranges('10.1.2.3', ['10.0.0.0/8']));
        self::assertFalse(ip_in_ranges('11.1.2.3', ['10.0.0.0/8']));
        self::assertTrue(ip_in_ranges('172.20.0.5', ['172.16.0.0/12']));
        self::assertFalse(ip_in_ranges('172.32.0.5', ['172.16.0.0/12']));
        self::assertTrue(ip_in_ranges('192.168.1.1', ['192.168.1.1']));
        self::assertTrue(ip_in_ranges('fd00::1', ['fd00::/8']));
        self::assertFalse(ip_in_ranges('10.0.0.1', ['fd00::/8']));
        self::assertFalse(ip_in_ranges('not-an-ip', ['0.0.0.0/0']));
    }

    public function testSafeUrl(): void
    {
        self::assertSame('https://store.steampowered.com/app/1', safe_url('https://store.steampowered.com/app/1'));
        self::assertNull(safe_url('javascript:alert(1)'));
        self::assertNull(safe_url(' JaVaScRiPt:alert(1)'));
        self::assertNull(safe_url('data:text/html,<b>x</b>'));
        self::assertNull(safe_url('/relative/path'));
        self::assertNull(safe_url(null));
    }

    #[DataProvider('proxies')]
    public function testForwardedHeadersAreOnlyTrustedFromLoopback(array $server, bool $https, string $ip): void
    {
        $_SERVER = $server;

        self::assertSame($https, request_is_https());
        self::assertSame($ip, client_ip());
    }
}
