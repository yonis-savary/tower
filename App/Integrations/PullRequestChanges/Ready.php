<?php

namespace App\Integrations\PullRequestChanges;

use App\Integrations\IntegrationChange;
use App\Integrations\PullRequestChangeDetector;
use App\Integrations\PullRequestState;

class Ready implements PullRequestChangeDetector
{
    public function detect(PullRequestState $before, PullRequestState $after): array
    {
        return $before->pullRequest->draft && !$after->pullRequest->draft
            ? [new IntegrationChange('pr.ready')]
            : [];
    }
}
