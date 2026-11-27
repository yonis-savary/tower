<?php

namespace App\Domain\Agenda;

use App\Models\AgendaItem;

/**
 * How an agenda item repeats. The item gives the first occurrence : the others start on the days the rule
 * gives, at the same time of day, and last as long as it does.
 */
final class Recurrence
{
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';
    public const MONTHLY = 'monthly';
    public const YEARLY = 'yearly';
    public const FREQUENCIES = [self::DAILY, self::WEEKLY, self::MONTHLY, self::YEARLY];

    public const MAX_INTERVAL = 99;

    // Far more periods than a range of the agenda holds : a guard against a rule that never reaches the range
    private const MAX_PERIODS = 1000;

    /**
     * @param list<int> $weekdays ISO-8601 days of a weekly item, 1 = Monday, sorted ; empty otherwise
     */
    public function __construct(
        public readonly string $frequency,
        public readonly int $interval = 1,
        public readonly array $weekdays = [],
        public readonly ?string $until = null,
    ) {}

    /**
     * @param ?array{frequency: string, interval?: ?int, weekdays?: ?list<int>, until?: ?string} $validated
     * @param string                                                                                $startsAt server time
     */
    public static function fromValidated(?array $validated, string $startsAt): ?self
    {
        if (null === $validated)
            return null;

        $frequency = $validated['frequency'];
        $weekdays = self::WEEKLY === $frequency
            ? array_map('intval', $validated['weekdays'] ?? [])
            : [];
        // A weekly item without days repeats on the day it starts
        if (self::WEEKLY === $frequency && !$weekdays)
            $weekdays = [(int) (new \DateTimeImmutable($startsAt))->format('N')];

        $weekdays = array_values(array_unique($weekdays));
        sort($weekdays);

        return new self($frequency, (int) ($validated['interval'] ?? 1), $weekdays, $validated['until'] ?? null);
    }

    public static function of(AgendaItem $item): ?self
    {
        if (!$item->isRecurring())
            return null;

        $weekdays = (string) $item->recurrence_weekdays;

        return new self(
            $item->recurrence,
            (int) $item->recurrence_interval,
            '' === $weekdays ? [] : array_map('intval', explode(',', $weekdays)),
            $item->recurrence_until?->format('Y-m-d'),
        );
    }

    public function until(?string $until): self
    {
        return new self($this->frequency, $this->interval, $this->weekdays, $until);
    }

    /**
     * The same rule for a series moved by some days : a weekly item keeps its days relative to its start.
     */
    public function shiftedBy(int $days): self
    {
        if (self::WEEKLY !== $this->frequency || 0 === $days % 7)
            return $this;

        $weekdays = array_map(fn (int $day) => ((($day - 1 + $days) % 7) + 7) % 7 + 1, $this->weekdays);
        sort($weekdays);

        return new self($this->frequency, $this->interval, $weekdays, $this->until);
    }

    /**
     * @return array{frequency: string, interval: int, weekdays: list<int>, until: ?string}
     */
    public function toArray(): array
    {
        return [
            'frequency' => $this->frequency,
            'interval' => $this->interval,
            'weekdays' => $this->weekdays,
            'until' => $this->until,
        ];
    }

    /**
     * The starts of the occurrences of an item starting at `$first`, that overlap [from, to).
     *
     * @return list<\DateTimeImmutable>
     */
    public function startsOverlapping(\DateTimeImmutable $first, \DateInterval $duration, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $this->startsBetween($first, $from->sub($duration)->modify('-1 day'), $to),
            fn (\DateTimeImmutable $start) => $start->add($duration) > $from,
        ));
    }

    /**
     * The start of the occurrence of the given day (Y-m-d), if the rule has one.
     */
    public function startOn(\DateTimeImmutable $first, string $day): ?\DateTimeImmutable
    {
        $from = new \DateTimeImmutable("{$day} 00:00:00");

        return $this->startsBetween($first, $from, $from->modify('+1 day'))[0] ?? null;
    }

    /**
     * The starts in [from, to), in order.
     *
     * @return list<\DateTimeImmutable>
     */
    public function startsBetween(\DateTimeImmutable $first, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $starts = [];
        // The periods before the one holding `from` are skipped, one more for the rounding
        $period = max(0, intdiv($this->periodsBetween($first, $from), $this->interval) - 1);

        for ($periods = 0; $periods < self::MAX_PERIODS; $periods++, $period++) {
            $candidates = $this->startsOfPeriod($first, $period * $this->interval);
            if (!$candidates)
                continue;

            foreach ($candidates as $start) {
                if ($start >= $to || (null !== $this->until && $start->format('Y-m-d') > $this->until))
                    return $starts;

                if ($start >= $first && $start >= $from)
                    $starts[] = $start;
            }
        }

        return $starts;
    }

    // Whole periods (days, weeks, months, years) from the period of `$first` to the one of `$moment`
    private function periodsBetween(\DateTimeImmutable $first, \DateTimeImmutable $moment): int
    {
        if ($moment <= $first)
            return 0;

        return match ($this->frequency) {
            self::DAILY => $this->daysBetween($first, $moment),
            self::WEEKLY => intdiv($this->daysBetween($this->weekOf($first), $moment), 7),
            self::MONTHLY => ((int) $moment->format('Y') * 12 + (int) $moment->format('n')) - ((int) $first->format('Y') * 12 + (int) $first->format('n')),
            self::YEARLY => (int) $moment->format('Y') - (int) $first->format('Y'),
        };
    }

    /**
     * The starts of the period `$offset` periods after the first one. A month or a year without the day of the
     * first occurrence (a 31st, a 29th of February) has none.
     *
     * @return list<\DateTimeImmutable>
     */
    private function startsOfPeriod(\DateTimeImmutable $first, int $offset): array
    {
        $day = (int) $first->format('j');

        switch ($this->frequency) {
            case self::DAILY:
                return [$first->modify("+{$offset} days")];

            case self::WEEKLY:
                $week = $this->weekOf($first)->modify('+'.($offset * 7).' days');

                return array_map(fn (int $weekday) => $week->modify('+'.($weekday - 1).' days'), $this->weekdays);

            case self::MONTHLY:
                $months = (int) $first->format('Y') * 12 + (int) $first->format('n') - 1 + $offset;
                $year = intdiv($months, 12);
                $month = $months % 12 + 1;

                return checkdate($month, $day, $year) ? [$first->setDate($year, $month, $day)] : [];

            default:
                $year = (int) $first->format('Y') + $offset;
                $month = (int) $first->format('n');

                return checkdate($month, $day, $year) ? [$first->setDate($year, $month, $day)] : [];
        }
    }

    // The Monday of the week of `$moment`, at its time of day
    private function weekOf(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->modify('-'.((int) $moment->format('N') - 1).' days');
    }

    private function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $from = new \DateTimeImmutable($from->format('Y-m-d'));
        $to = new \DateTimeImmutable($to->format('Y-m-d'));

        return (int) $from->diff($to)->format('%r%a');
    }
}
