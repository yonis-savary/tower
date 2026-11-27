<?php

namespace App\Resources;

use App\Domain\History\HistoryService;
use App\Models\EventDelivery;
use App\Models\History;
use Cube\Data\Bunch;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;

/**
 * A history line of an event, with how its deliveries went. Its deliveries must be loaded.
 *
 * @extends ModelResource<History>
 */
class RecentEventResource extends ModelResource
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
        $statuses = Bunch::wrap($model->isLoaded('eventDeliveries') ? $model->eventDeliveries : [])->map(fn (EventDelivery $delivery) => $delivery->status);

        return [
            'id' => $model->id,
            'type' => $model->type,
            'origin' => $model->origin,
            'details' => $model->details,
            'created_at' => AtomDate::format($model->created_at),
            'deliveries' => [
                // received : the handler has no answer to give, the device got the event
                'succeeded' => $statuses->filter(fn (string $status) => in_array($status, [HistoryService::DELIVERY_SUCCEEDED, HistoryService::DELIVERY_RECEIVED], true))->count(),
                'failed' => $statuses->filter(fn (string $status) => HistoryService::DELIVERY_FAILED === $status)->count(),
                'waiting' => $statuses->filter(fn (string $status) => in_array($status, [HistoryService::DELIVERY_PENDING, HistoryService::DELIVERY_RUNNING], true))->count(),
            ],
        ];
    }
}
