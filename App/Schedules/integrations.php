<?php

use App\Integrations\IntegrationService;
use App\Integrations\PullRequestSync;

Cube\everyMinute([PullRequestSync::class, 'syncAll'], 5);
Cube\daily([IntegrationService::class, 'purgeClosedPullRequests']);
