<?php

namespace App\Domain\Integrations\PullRequestChanges;

use App\Domain\Integrations\IntegrationChange;
use App\Domain\Integrations\PullRequestChangeDetector;
use App\Domain\Integrations\PullRequestState;

class Ready implements PullRequestChangeDetector
{
    public function detect(PullRequestState $before, PullRequestState $after): array
    {
        return $before->pullRequest->draft && !$after->pullRequest->draft
            ? [new IntegrationChange('pr.ready')]
            : [];
    }
}
