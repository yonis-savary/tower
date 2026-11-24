<?php

use App\Agenda\AgendaService;

Cube\everyMinute([AgendaService::class, 'announceUpcoming']);
