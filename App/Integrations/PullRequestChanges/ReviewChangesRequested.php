<?php

namespace App\Integrations\PullRequestChanges;

use App\Integrations\IntegrationChange;
use App\Integrations\PullRequestChangeDetector;
use App\Integrations\PullRequestData;
use App\Integrations\PullRequestState;

class ReviewChangesRequested implements PullRequestChangeDetector
{
    public function detect(PullRequestState $before, PullRequestState $after): array
    {
        $changes = [];

        foreach ($after->pullRequest->reviewers as $id => $reviewer) {
            if (PullRequestData::REVIEW_CHANGES_REQUESTED !== $reviewer['status'] || PullRequestData::REVIEW_CHANGES_REQUESTED === ($before->pullRequest->reviewers[$id]['status'] ?? null))
                continue;

            $changes[] = new IntegrationChange('pr.review.changes_requested', ['reviewer' => $reviewer['name']]);
        }

        return $changes;
    }
}
