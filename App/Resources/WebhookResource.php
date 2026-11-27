<?php

namespace App\Resources;

use App\Models\Webhook;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * The secret is never part of it : only the creation and the regeneration give it.
 *
 * @extends ModelResource<Webhook>
 */
#[Nullable(['last_called_at'])]
class WebhookResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return Webhook::class;
    }

    /**
     * @param Webhook $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'uuid' => $model->uuid,
            'events' => array_values(array_filter((array) (json_decode((string) $model->events, true) ?? []), 'is_string')),
            'enabled' => (bool) $model->enabled,
            // false : no secret, the address alone triggers it
            'secured' => (bool) $model->secured,
            'created_at' => $model->created_at,
            'last_called_at' => $model->last_called_at,
        ];
    }
}
