<?php

namespace App\Resources;

use App\Models\QuickAction;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<QuickAction>
 */
#[Nullable(['description'])]
class QuickActionResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return QuickAction::class;
    }

    /**
     * @param QuickAction $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'description' => $model->description,
            'type' => $model->type,
            'payload' => $model->payload,
        ];
    }
}
