<?php

use App\Domain\Integrations\IntegrationService;
use App\Domain\Integrations\Jira\JiraIssueSync;
use App\Domain\Integrations\PullRequestSync;

Cube\everyMinute([PullRequestSync::class, 'syncAll'], 5);
Cube\everyMinute([JiraIssueSync::class, 'syncAll'], 5);
Cube\daily([IntegrationService::class, 'purgeClosedPullRequests']);
