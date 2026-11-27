<?php

namespace App\Domain\Integrations\PullRequestChanges;

use App\Domain\Integrations\IntegrationChange;
use App\Domain\Integrations\PullRequestChangeDetector;
use App\Domain\Integrations\PullRequestData;
use App\Domain\Integrations\PullRequestState;

class PipelineSucceeded implements PullRequestChangeDetector
{
    public function detect(PullRequestState $before, PullRequestState $after): array
    {
        if (PullRequestData::PIPELINE_SUCCESS !== $after->pipeline || $before->pipeline === $after->pipeline)
            return [];

        return [new IntegrationChange('pr.pipeline.succeeded', ['from' => $before->pipeline, 'to' => $after->pipeline])];
    }
}
