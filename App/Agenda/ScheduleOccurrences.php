<?php

namespace App\Agenda;

use App\Models\ScheduledEvent;
use Cube\Routine\CronExpression;

class ScheduleOccurrences
{
    /**
     * @param list<int> $workspaces
     *
     * @return list<ScheduleOccurrence>
     */
    public function between(array $workspaces, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $schedules = ScheduledEvent::select()
            ->whereIn('workspace', $workspaces)
            ->where('enabled', true)
            ->order('id', 'ASC')
            ->fetch();

        $occurrences = [];
        foreach ($schedules as $schedule)
            array_push($occurrences, ...$this->occurrencesOf($schedule, $from, $to));

        usort($occurrences, fn (ScheduleOccurrence $a, ScheduleOccurrence $b) => [$a->at, $a->schedule] <=> [$b->at, $b->schedule]);

        return $occurrences;
    }

    /**
     * @return list<ScheduleOccurrence>
     */
    private function occurrencesOf(ScheduledEvent $schedule, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (null !== $schedule->run_at) {
            $runAt = new \DateTimeImmutable(is_string($schedule->run_at) ? $schedule->run_at : $schedule->run_at->format('Y-m-d H:i:s'));

            return $from <= $runAt && $runAt < $to ? [$this->occurrence($schedule, $runAt, false, 1)] : [];
        }

        [$minute, $hour, $dayOfTheMonth, $month, $dayOfTheWeek] = explode(' ', $schedule->cron);
        // Each field is matched on its own : walking the days, then their hours and minutes, never every minute of the range
        $days = new CronExpression("0 0 {$dayOfTheMonth} {$month} {$dayOfTheWeek}");
        $hours = $this->valuesOf(new CronExpression("0 {$hour} * * *"), 24, fn (int $value) => sprintf('2000-01-01 %02d:00', $value));
        $minutes = $this->valuesOf(new CronExpression("{$minute} * * * *"), 60, fn (int $value) => sprintf('2000-01-01 00:%02d', $value));
        if (!$hours || !$minutes)
            return [];

        $occurrences = [];
        for ($day = $from->setTime(0, 0); $day < $to; $day = $day->modify('+1 day')) {
            if (!$days->matches(\DateTime::createFromImmutable($day)))
                continue;

            if (count($minutes) > 1) {
                $first = $day->setTime($hours[0], $minutes[0]);
                if ($from <= $first && $first < $to)
                    $occurrences[] = $this->occurrence($schedule, $first, true, count($hours) * count($minutes));
                continue;
            }

            foreach ($hours as $value) {
                $run = $day->setTime($value, $minutes[0]);
                if ($from <= $run && $run < $to)
                    $occurrences[] = $this->occurrence($schedule, $run, false, 1);
            }
        }

        return $occurrences;
    }

    /**
     * @param \Closure(int): string $momentOf
     *
     * @return list<int>
     */
    private function valuesOf(CronExpression $expression, int $count, \Closure $momentOf): array
    {
        return array_values(array_filter(range(0, $count - 1), fn (int $value) => $expression->matches($momentOf($value))));
    }

    private function occurrence(ScheduledEvent $schedule, \DateTimeImmutable $at, bool $frequent, int $runs): ScheduleOccurrence
    {
        return new ScheduleOccurrence($schedule->id, $schedule->workspace, $schedule->name, $schedule->type, $at, $frequent, $runs);
    }
}
