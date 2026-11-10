<?php

namespace App\Integrations\PullRequestChanges;

use App\Integrations\IntegrationChange;
use App\Integrations\PullRequestChangeDetector;
use App\Integrations\PullRequestState;

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
