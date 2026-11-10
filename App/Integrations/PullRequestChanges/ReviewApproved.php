<?php

namespace App\Integrations\PullRequestChanges;

use App\Integrations\IntegrationChange;
use App\Integrations\PullRequestChangeDetector;
use App\Integrations\PullRequestData;
use App\Integrations\PullRequestState;

class ReviewApproved implements PullRequestChangeDetector
{
    public function detect(PullRequestState $before, PullRequestState $after): array
    {
        $changes = [];

        foreach ($after->pullRequest->reviewers as $id => $reviewer) {
            if (PullRequestData::REVIEW_APPROVED !== $reviewer['status'] || PullRequestData::REVIEW_APPROVED === ($before->pullRequest->reviewers[$id]['status'] ?? null))
                continue;

            $changes[] = new IntegrationChange('pr.review.approved', ['reviewer' => $reviewer['name']]);
        }

        return $changes;
    }
}
