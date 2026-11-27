<?php

namespace App\Domain\Integrations\Jira\Changes;

use App\Domain\Integrations\IntegrationChange;
use App\Domain\Integrations\Jira\JiraIssueChangeDetector;
use App\Domain\Integrations\Jira\JiraIssueData;

class Updated implements JiraIssueChangeDetector
{
    public function detect(JiraIssueData $before, JiraIssueData $after): array
    {
        $fields = array_keys(array_filter([
            'summary' => $before->summary !== $after->summary,
            'issue_type' => $before->issueType !== $after->issueType,
        ]));

        return [] === $fields
            ? []
            : [new IntegrationChange('jira.issue.updated', ['fields' => $fields, 'previous_summary' => $before->summary])];
    }
}
