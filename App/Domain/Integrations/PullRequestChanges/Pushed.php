<?php

namespace App\Domain\Integrations\PullRequestChanges;

use App\Domain\Integrations\IntegrationChange;
use App\Domain\Integrations\PullRequestChangeDetector;
use App\Domain\Integrations\PullRequestState;

class Pushed implements PullRequestChangeDetector
{
    public function detect(PullRequestState $before, PullRequestState $after): array
    {
        $from = $before->pullRequest->sourceCommit;
        $to = $after->pullRequest->sourceCommit;

        return $from !== $to
            ? [new IntegrationChange('pr.pushed', ['branch' => $after->pullRequest->sourceBranch, 'from' => $from, 'to' => $to])]
            : [];
    }
}
