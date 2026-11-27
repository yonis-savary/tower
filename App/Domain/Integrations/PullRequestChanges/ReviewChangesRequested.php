<?php

namespace App\Domain\Integrations\PullRequestChanges;

use App\Domain\Integrations\IntegrationChange;
use App\Domain\Integrations\PullRequestChangeDetector;
use App\Domain\Integrations\PullRequestData;
use App\Domain\Integrations\PullRequestState;

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
