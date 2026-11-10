<?php

namespace App\Integrations\Jira;

use App\Integrations\IntegrationChange;

interface JiraIssueChangeDetector
{
    /**
     * @return list<IntegrationChange>
     */
    public function detect(JiraIssueData $before, JiraIssueData $after): array;
}
