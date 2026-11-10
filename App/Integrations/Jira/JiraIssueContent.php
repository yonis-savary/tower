<?php

namespace App\Integrations\Jira;

final class JiraIssueContent
{
    /**
     * @param list<string>                                        $labels
     * @param list<array{author: string, created: string, body: string}> $comments
     */
    public function __construct(
        public readonly JiraIssueData $issue,
        public readonly string $description,
        public readonly ?string $assignee,
        public readonly ?string $reporter,
        public readonly array $labels,
        public readonly array $comments,
    ) {}
}
