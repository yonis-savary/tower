<?php

use App\Domain\AccessKey\Connector\ConfigDownloadService;

Cube\daily([ConfigDownloadService::class, 'purgeExpired']);
