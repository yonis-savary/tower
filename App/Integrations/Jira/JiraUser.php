<?php

namespace App\Integrations\Jira;

final class JiraUser
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $name,
        public readonly ?string $avatar,
    ) {}
}
