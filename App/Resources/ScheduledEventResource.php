<?php

namespace App\Resources;

use App\Models\ScheduledEvent;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<ScheduledEvent>
 */
#[Nullable(['run_at', 'last_run_at'])]
class ScheduledEventResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return ScheduledEvent::class;
    }

    /**
     * @param ScheduledEvent $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'type' => $model->type,
            'payload' => $model->payload,
            'cron' => $model->cron,
            'run_at' => $model->run_at,
            'enabled' => (bool) $model->enabled,
            'created_at' => $model->created_at,
            'last_run_at' => $model->last_run_at,
        ];
    }
}
