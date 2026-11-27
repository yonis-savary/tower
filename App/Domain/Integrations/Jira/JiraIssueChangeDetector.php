<?php

namespace App\Domain\Integrations\Jira;

use App\Domain\Integrations\IntegrationChange;

interface JiraIssueChangeDetector
{
    /**
     * @return list<IntegrationChange>
     */
    public function detect(JiraIssueData $before, JiraIssueData $after): array;
}
