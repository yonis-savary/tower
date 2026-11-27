<?php

namespace App\Domain\Integrations\Jira\Changes;

use App\Domain\Integrations\IntegrationChange;
use App\Domain\Integrations\Jira\JiraIssueChangeDetector;
use App\Domain\Integrations\Jira\JiraIssueData;

class PriorityChanged implements JiraIssueChangeDetector
{
    public function detect(JiraIssueData $before, JiraIssueData $after): array
    {
        if ($before->priority === $after->priority)
            return [];

        return [new IntegrationChange('jira.issue.priority_changed', ['from' => $before->priority, 'to' => $after->priority])];
    }
}
