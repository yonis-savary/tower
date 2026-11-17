<?php

use App\Connector\ConfigDownloadService;

Cube\daily([ConfigDownloadService::class, 'purgeExpired']);
