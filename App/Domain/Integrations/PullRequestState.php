<?php

namespace App\Domain\Integrations;

final class PullRequestState
{
    public function __construct(
        public readonly PullRequestData $pullRequest,
        public readonly string $pipeline,
    ) {}
}
