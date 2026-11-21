<?php

namespace App\Resources;

use App\Models\Task;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<Task>
 */
#[Nullable(['sprint', 'jira_issue', 'link'])]
class TaskResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return Task::class;
    }

    /**
     * @param Task $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'sprint' => $model->sprint,
            'title' => $model->title,
            'description' => $model->description,
            'status' => $model->status,
            'position' => $model->position,
            'jira_issue' => $model->jira_issue,
            'link' => $model->link,
        ];
    }
}
