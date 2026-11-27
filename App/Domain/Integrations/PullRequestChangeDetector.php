<?php

namespace App\Domain\Integrations;

interface PullRequestChangeDetector
{
    /**
     * @return list<IntegrationChange>
     */
    public function detect(PullRequestState $before, PullRequestState $after): array;
}
