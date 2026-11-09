<?php

namespace App\Resources;

use App\Models\Sprint;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<Sprint>
 */
#[Nullable(['opened_at', 'closed_at'])]
class SprintResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return Sprint::class;
    }

    /**
     * @param Sprint $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'state' => $model->state,
            'opened_at' => AtomDate::format($model->opened_at),
            'closed_at' => AtomDate::format($model->closed_at),
        ];
    }
}
