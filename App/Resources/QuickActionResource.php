<?php

namespace App\Resources;

use App\Models\QuickAction;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;

/**
 * @extends ModelResource<QuickAction>
 */
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
            'type' => $model->type,
            'payload' => $model->payload,
        ];
    }
}
