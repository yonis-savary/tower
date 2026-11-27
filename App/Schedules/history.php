<?php

use App\Domain\History\HistoryService;

Cube\daily([HistoryService::class, 'purgeExpired']);
