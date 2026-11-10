<?php

namespace App\Integrations\Jira\Changes;

use App\Integrations\IntegrationChange;
use App\Integrations\Jira\JiraIssueChangeDetector;
use App\Integrations\Jira\JiraIssueData;

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
