<?php

namespace App\Resources;

use App\Models\EventDelivery;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<EventDelivery>
 */
#[Nullable(['hostname', 'response', 'responded_at', 'exit_code'])]
class EventDeliveryResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return EventDelivery::class;
    }

    /**
     * @param EventDelivery $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'connection_uuid' => $model->connection_uuid,
            'hostname' => $model->hostname,
            'payload' => $model->payload,
            'status' => $model->status,
            'exit_code' => $model->exit_code,
            'response' => $model->response,
            'responded_at' => AtomDate::format($model->responded_at),
        ];
    }
}
