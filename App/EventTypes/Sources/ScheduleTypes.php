<?php

namespace App\EventTypes\Sources;

use App\EventTypes\EventTypeSource;
use App\Models\ScheduledEvent;

class ScheduleTypes implements EventTypeSource
{
    public static function name(): string
    {
        return 'schedule';
    }

    public function typesOf(int $workspace): array
    {
        return array_map(fn (ScheduledEvent $schedule) => $schedule->type, ScheduledEvent::select()->where('workspace', $workspace)->fetch());
    }
}
