<?php

namespace App\Integrations\Jira\Changes;

use App\Integrations\IntegrationChange;
use App\Integrations\Jira\JiraIssueChangeDetector;
use App\Integrations\Jira\JiraIssueData;

class PriorityChanged implements JiraIssueChangeDetector
{
    public function detect(JiraIssueData $before, JiraIssueData $after): array
    {
        if ($before->priority === $after->priority)
            return [];

        return [new IntegrationChange('jira.issue.priority_changed', ['from' => $before->priority, 'to' => $after->priority])];
    }
}
