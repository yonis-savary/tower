<?php

namespace App\Resources;

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
            'last_used_at' => AtomDate::format($model->last_used_at),
        ];
    }
}
