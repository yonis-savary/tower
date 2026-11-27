<?php

namespace App\Domain\Integrations\PullRequestChanges;

use App\Domain\Integrations\IntegrationChange;
use App\Domain\Integrations\PullRequestChangeDetector;
use App\Domain\Integrations\PullRequestState;

class Commented implements PullRequestChangeDetector
{
    public function detect(PullRequestState $before, PullRequestState $after): array
    {
        $from = $before->pullRequest->commentCount;
        $to = $after->pullRequest->commentCount;

        return $to > $from
            ? [new IntegrationChange('pr.commented', ['count' => $to - $from, 'from' => $from, 'to' => $to])]
            : [];
    }
}
