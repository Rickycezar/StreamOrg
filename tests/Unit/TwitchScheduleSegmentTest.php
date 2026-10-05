<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** What one content item looks like as a Twitch schedule segment. */
final class TwitchScheduleSegmentTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function testSegmentInUtcWithZoneLengthAndCategory(): void
    {
        $segment = TwitchSchedule::segment([
            'title'           => "  Hades   run\n#Supergiant ",
            'scheduled_start' => '2026-10-10 20:00:00-03',
            'minutes'         => 150,
            'category_id'     => '510590',
        ]);

        self::assertSame([
            'start_time'  => '2026-10-10T23:00:00Z',
            'timezone'    => 'America/Sao_Paulo',
            'duration'    => '150',
            'title'       => 'Hades run #Supergiant',
            'category_id' => '510590',
        ], $segment);
    }

    public function testLengthAndTitleStayWithinTwitchLimits(): void
    {
        $short = TwitchSchedule::segment(['title' => str_repeat('a', 200), 'scheduled_start' => '2026-10-10 20:00:00-03', 'minutes' => 15, 'category_id' => null]);
        $long  = TwitchSchedule::segment(['title' => 'x', 'scheduled_start' => '2026-10-10 20:00:00-03', 'minutes' => 1440, 'category_id' => null]);

        self::assertSame('30', $short['duration']);
        self::assertSame(140, mb_strlen($short['title']));
        self::assertArrayNotHasKey('category_id', $short);
        self::assertSame('1380', $long['duration']);
    }

    public function testCreatedSegmentIsFoundAmongTheWholeSchedule(): void
    {
        $segment  = ['start_time' => '2026-10-10T23:00:00Z', 'title' => 'Hades run'];
        $response = ['status' => 200, 'error' => null, 'body' => (string) json_encode(['data' => ['segments' => [
            ['id' => 'recurring', 'start_time' => '2026-10-09T23:00:00Z', 'title' => 'Weekly'],
            ['id' => 'mine', 'start_time' => '2026-10-10T23:00:00+00:00', 'title' => 'Hades run'],
        ]]])];

        self::assertSame('mine', TwitchSchedule::createdId($response, $segment));
    }
}
