<?php

namespace App\Resources;

use App\Models\JiraIssue;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<JiraIssue>
 */
#[Nullable(['priority', 'sprint_id', 'sprint_name', 'sprint_state', 'assignee_id', 'assignee_name', 'assignee_avatar'])]
class JiraIssueResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return JiraIssue::class;
    }

    /**
     * @param JiraIssue $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'key' => $model->issue_key,
            'summary' => $model->summary,
            'url' => $model->url,
            'project' => $model->project,
            'issue_type' => $model->issue_type,
            'priority' => $model->priority,
            'status_id' => $model->status_id,
            'status' => $model->status,
            'status_category' => $model->status_category,
            'sprint_id' => $model->sprint_id,
            'sprint_name' => $model->sprint_name,
            'sprint_state' => $model->sprint_state,
            'assignee_id' => $model->assignee_id,
            'assignee_name' => $model->assignee_name,
            'assignee_avatar' => $model->assignee_avatar,
            'tracked' => (bool) $model->tracked,
        ];
    }
}
