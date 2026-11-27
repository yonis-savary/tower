<?php

namespace App\Resources;

use App\Models\PushSubscription;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<PushSubscription>
 */
#[Nullable(['user_agent', 'last_success_at'])]
class PushSubscriptionResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return PushSubscription::class;
    }

    /**
     * @param PushSubscription $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            // Lets a browser find its own subscription in the list
            'endpoint' => $model->endpoint,
            'user_agent' => $model->user_agent,
            'created_at' => $model->created_at,
            'last_success_at' => $model->last_success_at,
        ];
    }
}
