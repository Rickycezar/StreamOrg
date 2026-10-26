<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Undated content added to today lands in the first gap long enough for it. */
final class TodaySlotTest extends TestCase
{
    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-26 ' . $time, new DateTimeZone('UTC'));
    }

    public function testAnEmptyDayStartsAtTheGivenTime(): void
    {
        $slot = TodaySlot::firstGap($this->at('14:00'), [], 120, $this->at('23:59'));

        self::assertSame('14:00', $slot?->format('H:i'));
    }

    public function testItGoesIntoAGapThatFitsAndSkipsOneThatDoesNot(): void
    {
        $busy = [
            [$this->at('15:00'), $this->at('17:00')],
            [$this->at('18:00'), $this->at('20:00')],
        ];

        self::assertSame('14:00', TodaySlot::firstGap($this->at('14:00'), $busy, 60, $this->at('23:59'))?->format('H:i'));
        self::assertSame('20:00', TodaySlot::firstGap($this->at('14:00'), $busy, 90, $this->at('23:59'))?->format('H:i'));
    }

    public function testItComesRightAfterWhatIsAlreadyOnAndRoundsUp(): void
    {
        $busy = [[$this->at('13:00'), $this->at('15:10')]];

        self::assertSame('15:15', TodaySlot::firstGap($this->at('14:00'), $busy, 60, $this->at('23:59'))?->format('H:i'));
    }

    public function testAFullDayHasNoRoom(): void
    {
        $busy = [[$this->at('10:00'), new DateTimeImmutable('2026-10-27 01:00', new DateTimeZone('UTC'))]];

        self::assertNull(TodaySlot::firstGap($this->at('14:00'), $busy, 60, new DateTimeImmutable('2026-10-27 00:00', new DateTimeZone('UTC'))));
    }
}
