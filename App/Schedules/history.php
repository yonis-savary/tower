<?php

use App\Services\HistoryService;

Cube\daily([HistoryService::class, 'purgeExpired']);
