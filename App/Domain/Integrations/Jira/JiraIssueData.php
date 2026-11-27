<?php

namespace App\Domain\Integrations\Jira;

final class JiraIssueData
{
    public const CATEGORY_TODO = 'todo';
    public const CATEGORY_IN_PROGRESS = 'in_progress';
    public const CATEGORY_DONE = 'done';
    public const CATEGORIES = [self::CATEGORY_TODO, self::CATEGORY_IN_PROGRESS, self::CATEGORY_DONE];

    /**
     * @param list<JiraIssueData> $subtasks
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $key,
        public readonly string $summary,
        public readonly string $url,
        public readonly string $project,
        public readonly string $issueType,
        public readonly ?string $priority,
        public readonly string $statusId,
        public readonly string $status,
        public readonly string $statusCategory,
        public readonly ?JiraSprint $sprint,
        public readonly ?JiraUser $assignee,
        public readonly array $subtasks = [],
    ) {}
}
