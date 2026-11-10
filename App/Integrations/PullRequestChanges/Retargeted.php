<?php

namespace App\Integrations\PullRequestChanges;

use App\Integrations\IntegrationChange;
use App\Integrations\PullRequestChangeDetector;
use App\Integrations\PullRequestState;

class Retargeted implements PullRequestChangeDetector
{
    public function detect(PullRequestState $before, PullRequestState $after): array
    {
        $from = $before->pullRequest->targetBranch;
        $to = $after->pullRequest->targetBranch;

        return $from !== $to
            ? [new IntegrationChange('pr.retargeted', ['from' => $from, 'to' => $to])]
            : [];
    }
}
