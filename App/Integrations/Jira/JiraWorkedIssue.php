<?php

namespace App\Integrations\Jira;

/**
 * An issue the account of the token worked on during a period, as read from Jira.
 */
class JiraWorkedIssue
{
    /**
     * @param ?string $created ISO 8601
     * @param ?string $resolved ISO 8601, when Jira resolved it
     * @param list<array{at:string,from:?string,to:?string,from_id:?string,to_id:?string}> $changes every status change Jira kept, oldest first
     */
    public function __construct(
        public readonly string $key,
        public readonly string $summary,
        public readonly string $url,
        public readonly string $project,
        public readonly string $issueType,
        public readonly string $statusId,
        public readonly string $status,
        public readonly string $statusCategory,
        public readonly ?string $created,
        public readonly ?string $resolved,
        public readonly array $changes,
    ) {}
}
