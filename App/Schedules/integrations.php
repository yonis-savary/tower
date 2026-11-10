<?php

use App\Integrations\IntegrationService;
use App\Integrations\Jira\JiraIssueSync;
use App\Integrations\PullRequestSync;

Cube\everyMinute([PullRequestSync::class, 'syncAll'], 5);
Cube\everyMinute([JiraIssueSync::class, 'syncAll'], 5);
Cube\daily([IntegrationService::class, 'purgeClosedPullRequests']);
