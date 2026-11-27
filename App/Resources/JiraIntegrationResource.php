<?php

namespace App\Resources;

use App\Models\Integration;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<Integration>
 */
#[Nullable(['last_synced_at', 'last_error'])]
class JiraIntegrationResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return Integration::class;
    }

    /**
     * @param Integration $model
     */
    public function toArray(Model $model): array
    {
        $settings = json_decode($model->settings, true) ?? [];

        return [
            'site' => (string) ($settings['site'] ?? ''),
            'email' => (string) ($settings['email'] ?? ''),
            'last_synced_at' => $model->last_synced_at,
            'last_error' => $model->last_error,
        ];
    }
}
