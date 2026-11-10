<?php

namespace App\Integrations\Jira\Changes;

use App\Integrations\IntegrationChange;
use App\Integrations\Jira\JiraIssueChangeDetector;
use App\Integrations\Jira\JiraIssueData;

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
