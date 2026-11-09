<?php

namespace App\Resources;

use App\Models\Workspace;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;

/**
 * @extends ModelResource<Workspace>
 */
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
        ];
    }
}
