<?php

namespace App\Resources;

use App\Domain\AccessKey\Connector\HandlerRules;
use App\Models\AccessKey;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<AccessKey>
 */
#[Nullable(['last_used_at'])]
class AccessKeyResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return AccessKey::class;
    }

    /**
     * @param AccessKey $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'client_id' => $model->client_id,
            // Patterns of the events sent to the devices using this key
            'listen' => array_values(array_filter((array) (json_decode((string) $model->listen, true) ?? []), 'is_string')),
            'last_used_at' => $model->last_used_at,
            // The rules written into the downloaded configuration, see HandlerRules
            'handlers' => HandlerRules::decode($model->handlers),
        ];
    }
}
