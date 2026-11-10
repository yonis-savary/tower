<?php

namespace App\Integrations\PullRequestChanges;

use App\Integrations\IntegrationChange;
use App\Integrations\PullRequestChangeDetector;
use App\Integrations\PullRequestState;

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
