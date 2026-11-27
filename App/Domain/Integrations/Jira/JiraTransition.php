<?php

namespace App\Domain\Integrations\Jira;

final class JiraTransition
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $statusId,
        public readonly string $status,
        public readonly string $statusCategory,
    ) {}
}
