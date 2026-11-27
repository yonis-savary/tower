<?php

use App\Domain\Agenda\AgendaService;

Cube\everyMinute([AgendaService::class, 'announceUpcoming']);
