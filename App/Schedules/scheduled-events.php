<?php

use App\Services\ScheduledEventService;

Cube\everyMinute([ScheduledEventService::class, 'triggerDue']);
