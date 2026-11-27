<?php

namespace App\Domain\Integrations\Jira;

final class JiraColumn
{
    /**
     * @param list<string> $statusIds
     */
    public function __construct(
        public readonly string $name,
        public readonly array $statusIds,
    ) {}
}
