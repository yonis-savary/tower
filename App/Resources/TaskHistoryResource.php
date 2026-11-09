<?php

namespace App\Resources;

use App\Models\History;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<History>
 */
#[Nullable(['author'])]
class TaskHistoryResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return History::class;
    }

    /**
     * @param History $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'type' => $model->type,
            'details' => $model->details,
            'author' => $model->isLoaded('_user') ? $model->_user?->email : null,
            'created_at' => AtomDate::format($model->created_at),
        ];
    }
}
