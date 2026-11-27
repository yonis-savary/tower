<?php

namespace App\Resources;

use App\Models\ApiToken;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<ApiToken>
 */
#[Nullable(['last_used_at', 'expires_at'])]
class ApiTokenResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return ApiToken::class;
    }

    /**
     * @param ApiToken $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'scope' => $model->scope,
            'created_at' => $model->created_at,
            'last_used_at' => $model->last_used_at,
            'expires_at' => $model->expires_at,
        ];
    }
}
