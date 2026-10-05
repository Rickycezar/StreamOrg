<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** EventSub deliveries are only trusted with a valid, recent signature. */
final class TwitchEventSubTest extends TestCase
{
    protected function setUp(): void
    {
        Config::reset(['security' => ['app_key' => 'phpunit-key'], 'app' => ['base_url' => 'https://streamorg.example']]);
    }

    protected function tearDown(): void
    {
        Config::reset();
    }

    private static function sign(string $id, string $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $id . $timestamp . $body, TwitchEventSub::secret());
    }

    public function testValidSignatureIsAccepted(): void
    {
        $timestamp = gmdate('Y-m-d\TH:i:s') . '.123456789Z';
        $body      = '{"event":{}}';

        self::assertTrue(TwitchEventSub::verify('msg-1', $timestamp, self::sign('msg-1', $timestamp, $body), $body));
    }

    public function testTamperedBodyIsRefused(): void
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        self::assertFalse(TwitchEventSub::verify('msg-1', $timestamp, self::sign('msg-1', $timestamp, '{"a":1}'), '{"a":2}'));
    }

    public function testOldMessageIsRefused(): void
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $body      = '{}';

        self::assertFalse(TwitchEventSub::verify('msg-1', $timestamp, self::sign('msg-1', $timestamp, $body), $body));
    }

    public function testCallbackNeedsAPublicHttpsAddress(): void
    {
        self::assertSame('https://streamorg.example/twitch/eventsub', TwitchEventSub::callbackUrl());

        foreach (['https://localhost:8443', 'http://streamorg.example', 'https://127.0.0.1', 'https://streamorg.example:8443'] as $base) {
            Config::reset(['app' => ['base_url' => $base]]);
            self::assertNull(TwitchEventSub::callbackUrl(), $base);
        }
    }
}
