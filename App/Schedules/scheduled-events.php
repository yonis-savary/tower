<?php

use App\Domain\Event\ScheduledEventService;

Cube\everyMinute([ScheduledEventService::class, 'triggerDue']);
