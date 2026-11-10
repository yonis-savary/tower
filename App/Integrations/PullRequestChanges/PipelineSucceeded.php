<?php

namespace App\Integrations\PullRequestChanges;

use App\Integrations\IntegrationChange;
use App\Integrations\PullRequestChangeDetector;
use App\Integrations\PullRequestData;
use App\Integrations\PullRequestState;

class PipelineSucceeded implements PullRequestChangeDetector
{
    public function detect(PullRequestState $before, PullRequestState $after): array
    {
        if (PullRequestData::PIPELINE_SUCCESS !== $after->pipeline || $before->pipeline === $after->pipeline)
            return [];

        return [new IntegrationChange('pr.pipeline.succeeded', ['from' => $before->pipeline, 'to' => $after->pipeline])];
    }
}
