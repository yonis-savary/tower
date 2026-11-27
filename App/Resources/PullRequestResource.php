<?php

namespace App\Resources;

use App\Models\PullRequest;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;

/**
 * @extends ModelResource<PullRequest>
 */
class PullRequestResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return PullRequest::class;
    }

    /**
     * @param PullRequest $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            // The id the forge shows (#12), unique in its repository only
            'number' => $model->external_id,
            'repository' => $model->repository,
            'title' => $model->title,
            'author' => $model->author,
            'state' => $model->state,
            'draft' => (bool) $model->draft,
            'source_branch' => $model->source_branch,
            'target_branch' => $model->target_branch,
            'url' => $model->url,
            'comment_count' => (int) $model->comment_count,
            'pipeline_status' => $model->pipeline_status,
            'synced_at' => $model->synced_at,
        ];
    }
}
