<?php

namespace App\Integrations\Jira;

final class JiraSprint
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $state,
    ) {}
}
