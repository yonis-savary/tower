<?php

namespace App\Resources;

use App\Models\SprintLink;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<SprintLink>
 */
#[Nullable(['label'])]
class SprintLinkResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return SprintLink::class;
    }

    /**
     * @param SprintLink $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'url' => $model->url,
            'label' => $model->label,
        ];
    }
}
