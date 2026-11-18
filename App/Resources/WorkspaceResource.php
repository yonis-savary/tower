<?php

namespace App\Resources;

use App\Models\Workspace;
use App\Services\WorkspaceService;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<Workspace>
 */
#[Nullable(['accent_color'])]
class WorkspaceResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return Workspace::class;
    }

    /**
     * @param Workspace $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'accent_color' => $model->accent_color,
            'sections' => WorkspaceService::sectionsOf($model),
            'mcp_access' => $model->mcp_access,
        ];
    }
}
