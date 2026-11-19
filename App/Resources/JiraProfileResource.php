<?php

namespace App\Resources;

use App\Models\JiraProfile;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<JiraProfile>
 */
#[Nullable(['name', 'board_name', 'jql'])]
class JiraProfileResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return JiraProfile::class;
    }

    /**
     * @param JiraProfile $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'board' => $model->board,
            'board_name' => $model->board_name,
            'jql' => $model->jql,
            'columns' => json_decode($model->columns, true),
        ];
    }
}
