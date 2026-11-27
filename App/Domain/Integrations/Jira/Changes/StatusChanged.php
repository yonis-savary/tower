<?php

namespace App\Domain\Integrations\Jira\Changes;

use App\Domain\Integrations\IntegrationChange;
use App\Domain\Integrations\Jira\JiraIssueChangeDetector;
use App\Domain\Integrations\Jira\JiraIssueData;

class StatusChanged implements JiraIssueChangeDetector
{
    public function detect(JiraIssueData $before, JiraIssueData $after): array
    {
        if ($before->statusId === $after->statusId)
            return [];

        return [new IntegrationChange('jira.issue.status_changed', [
            'from' => $before->status,
            'to' => $after->status,
            'from_category' => $before->statusCategory,
            'to_category' => $after->statusCategory,
        ])];
    }
}
