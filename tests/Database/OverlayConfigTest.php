<?php
declare(strict_types=1);

/** The administrators' overlay settings: kept within bounds, addresses checked. */
final class OverlayConfigTest extends DatabaseTestCase
{
    public function testSaveKeepsNumbersWithinBounds(): void
    {
        $admin = $this->createUser('phpunit_overlay_admin');

        OverlayConfig::save([
            'enabled' => '1', 'types_on' => [], 'poll_seconds' => 0, 'max_per_user' => 9999,
            'sound_max_mb' => 500, 'image_max_mb' => 2, 'quota_mb' => -5,
            'base_url' => 'https://overlay.example.com/', 'live_url' => '',
        ], $admin);

        self::assertTrue(OverlayConfig::enabled());
        self::assertFalse(OverlayConfig::typeAllowed('alert'));
        self::assertSame(2, OverlayConfig::pollSeconds());
        self::assertSame(200, OverlayConfig::maxPerUser());
        self::assertSame(10 * 1024 * 1024, OverlayConfig::maxBytes('sound'));
        self::assertSame(2 * 1024 * 1024, OverlayConfig::maxBytes('image'));
        self::assertSame(1024 * 1024, OverlayConfig::quotaBytes());
        self::assertSame('https://overlay.example.com', OverlayConfig::baseUrl());
    }

    public function testBadAddressesAreRefused(): void
    {
        $admin = $this->createUser('phpunit_overlay_admin');

        foreach ([['base_url' => 'javascript:alert(1)'], ['live_url' => 'https://not-a-socket.example.com']] as $in) {
            try {
                OverlayConfig::save($in + ['enabled' => '1'], $admin);
                self::fail('Saved ' . json_encode($in));
            } catch (UserError) {
            }
        }

        self::assertTrue(true);
    }
}
