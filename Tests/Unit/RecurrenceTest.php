<?php

namespace Tests\Unit;

use App\Domain\Agenda\Recurrence;
use PHPUnit\Framework\TestCase;

class RecurrenceTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function starts(Recurrence $recurrence, string $first, string $from, string $to, string $duration = 'PT1H'): array
    {
        return array_map(
            fn (\DateTimeImmutable $start) => $start->format('Y-m-d H:i'),
            $recurrence->startsOverlapping(new \DateTimeImmutable($first), new \DateInterval($duration), new \DateTimeImmutable($from), new \DateTimeImmutable($to)),
        );
    }

    public function testDailyEveryTwoDaysUntilADay()
    {
        $this->assertSame(
            ['2026-12-01 09:00', '2026-12-03 09:00'],
            $this->starts(new Recurrence(Recurrence::DAILY, 2, [], '2026-12-04'), '2026-11-29 09:00', '2026-12-01 00:00', '2026-12-08 00:00'),
        );
    }

    public function testWeeklyOnSomeDaysEveryOtherWeek()
    {
        // Monday 2026-11-30 : the week of 2026-12-07 is skipped
        $this->assertSame(
            ['2026-12-02 09:00', '2026-12-14 09:00', '2026-12-16 09:00'],
            $this->starts(new Recurrence(Recurrence::WEEKLY, 2, [1, 3]), '2026-12-02 09:00', '2026-11-30 00:00', '2026-12-21 00:00'),
        );
    }

    public function testMonthlyAndYearlySkipThePeriodsWithoutTheirDay()
    {
        $this->assertSame(
            ['2026-12-31 09:00', '2027-01-31 09:00', '2027-03-31 09:00'],
            $this->starts(new Recurrence(Recurrence::MONTHLY), '2026-10-31 09:00', '2026-11-01 00:00', '2027-04-01 00:00'),
        );
        $this->assertSame(
            ['2032-02-29 00:00'],
            $this->starts(new Recurrence(Recurrence::YEARLY), '2028-02-29 00:00', '2029-01-01 00:00', '2032-12-31 00:00', 'P1D'),
        );
    }

    public function testAnOccurrenceStartedBeforeTheRangeOverlapsIt()
    {
        $this->assertSame(
            ['2026-11-30 22:00', '2026-12-01 22:00'],
            $this->starts(new Recurrence(Recurrence::DAILY), '2026-01-01 22:00', '2026-12-01 00:00', '2026-12-02 00:00', 'PT4H'),
        );
    }

    public function testAnOldItemIsNotWalkedFromItsStart()
    {
        $this->assertSame(
            ['2026-12-01 08:00'],
            $this->starts(new Recurrence(Recurrence::DAILY), '1990-01-01 08:00', '2026-12-01 00:00', '2026-12-02 00:00'),
        );
    }

    public function testTheOccurrenceOfADay()
    {
        $recurrence = new Recurrence(Recurrence::WEEKLY, 1, [2]);
        $first = new \DateTimeImmutable('2026-12-01 09:00');

        $this->assertSame('2026-12-08 09:00', $recurrence->startOn($first, '2026-12-08')?->format('Y-m-d H:i'));
        $this->assertNull($recurrence->startOn($first, '2026-12-09'));
        $this->assertNull($recurrence->startOn($first, '2026-11-24'));
    }

    public function testAWeeklyRuleMovesWithItsItem()
    {
        $this->assertSame([2, 4], (new Recurrence(Recurrence::WEEKLY, 1, [1, 3]))->shiftedBy(1)->weekdays);
        $this->assertSame([1, 7], (new Recurrence(Recurrence::WEEKLY, 1, [1, 2]))->shiftedBy(-1)->weekdays);
    }
}
