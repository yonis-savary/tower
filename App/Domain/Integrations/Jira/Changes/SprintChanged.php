<?php

namespace App\Domain\Integrations\Jira\Changes;

use App\Domain\Integrations\IntegrationChange;
use App\Domain\Integrations\Jira\JiraIssueChangeDetector;
use App\Domain\Integrations\Jira\JiraIssueData;

class SprintChanged implements JiraIssueChangeDetector
{
    public function detect(JiraIssueData $before, JiraIssueData $after): array
    {
        if ($before->sprint?->id === $after->sprint?->id)
            return [];

        return [new IntegrationChange('jira.issue.sprint_changed', ['from' => $before->sprint?->name, 'to' => $after->sprint?->name])];
    }
}
