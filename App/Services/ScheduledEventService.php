<?php

namespace App\Services;

use App\Models\ScheduledEvent;
use Cube\Data\Bunch;
use Cube\Routine\CronExpression;

class ScheduledEventService
{
    public function __construct(
        protected EventService $events,
    ) {}

    /**
     * @return Bunch<int,ScheduledEvent> Oldest first
     */
    public function listFor(int $workspace): Bunch
    {
        return Bunch::wrap(ScheduledEvent::select()->where('workspace', $workspace)->order('id', 'ASC')->fetch());
    }

    public function find(int $workspace, int $id): ?ScheduledEvent
    {
        $schedule = ScheduledEvent::find($id);

        return $schedule && $schedule->workspace === $workspace ? $schedule : null;
    }

    public function create(int $workspace, string $name, string $type, string $payload, ?string $cron, ?string $runAt): ScheduledEvent
    {
        // Dates as strings : generated models type a DATETIME column as DATE, which drops the time
        $schedule = new ScheduledEvent([
            'workspace' => $workspace,
            'name' => $name,
            'type' => $type,
            'payload' => $payload,
            'enabled' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->applyTiming($schedule, $cron, $runAt)->save();
    }

    public function update(ScheduledEvent $schedule, string $name, string $type, string $payload, ?string $cron, ?string $runAt, bool $enabled): ScheduledEvent
    {
        $schedule->name = $name;
        $schedule->type = $type;
        $schedule->payload = $payload;
        $schedule->enabled = $enabled;

        return $this->applyTiming($schedule, $cron, $runAt)->save();
    }

    public function delete(ScheduledEvent $schedule): void
    {
        ScheduledEvent::deleteId($schedule->id);
    }

    public function triggerDue(): void
    {
        $this->triggerDueAt(new \DateTimeImmutable());
    }

    public function triggerDueAt(\DateTimeImmutable $now): void
    {
        $minute = $now->format('Y-m-d H:i');
        $moment = new \DateTime($minute);

        $schedules = ScheduledEvent::select()->where('enabled', true)->order('id', 'ASC')->fetch();

        foreach ($schedules as $schedule) {
            $runAt = null === $schedule->run_at ? null : $this->minuteOf($schedule->run_at);

            // A one-time schedule left behind by a stopped scheduler is dropped, never caught up
            if (null !== $runAt && $runAt < $minute) {
                $schedule->enabled = false;
                $schedule->save();
                continue;
            }

            $isDue = (new CronExpression($schedule->cron))->matches($moment) && (null === $runAt || $runAt === $minute);
            if (!$isDue)
                continue;

            $schedule->last_run_at = "{$minute}:00";
            $schedule->enabled = null === $runAt;
            $schedule->save();

            $this->events->triggerFromSchedule($schedule->workspace, $schedule->id, $schedule->name, $schedule->type, $schedule->payload);
        }
    }

    private function applyTiming(ScheduledEvent $schedule, ?string $cron, ?string $runAt): ScheduledEvent
    {
        if (null === $runAt) {
            $schedule->cron = $cron;
            $schedule->run_at = null;

            return $schedule;
        }

        $date = new \DateTimeImmutable($runAt);
        $schedule->cron = sprintf('%d %d %d %d *', $date->format('i'), $date->format('G'), $date->format('j'), $date->format('n'));
        $schedule->run_at = $date->format('Y-m-d H:i:00');

        return $schedule;
    }

    private function minuteOf(\DateTimeInterface|string $date): string
    {
        return ($date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable($date))->format('Y-m-d H:i');
    }
}
