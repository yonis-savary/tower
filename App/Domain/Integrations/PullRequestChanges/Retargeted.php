<?php

namespace App\Domain\Integrations\PullRequestChanges;

use App\Domain\Integrations\IntegrationChange;
use App\Domain\Integrations\PullRequestChangeDetector;
use App\Domain\Integrations\PullRequestState;

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
