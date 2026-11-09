<?php

namespace App\Resources;

use App\Models\History;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;

/**
 * @extends ModelResource<History>
 */
class HistoryResource extends ModelResource
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
            'origin' => $model->origin,
            'details' => $model->details,
            'created_at' => AtomDate::format($model->created_at),
        ];
    }
}
