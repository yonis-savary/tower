<?php

namespace App\Integrations;

interface PullRequestChangeDetector
{
    /**
     * @return list<IntegrationChange>
     */
    public function detect(PullRequestState $before, PullRequestState $after): array;
}
