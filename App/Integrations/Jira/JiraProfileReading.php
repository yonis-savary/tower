<?php

namespace App\Integrations\Jira;

final class JiraProfileReading
{
    /**
     * @param list<JiraColumn>    $columns
     * @param list<string>        $doneStatuses
     * @param list<JiraIssueData> $issues
     */
    public function __construct(
        public readonly string $boardName,
        public readonly array $columns,
        public readonly array $doneStatuses,
        public readonly array $issues,
    ) {}
}
