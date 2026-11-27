<?php

namespace App\Resources;

use App\Domain\Documentation\DocumentationService;
use App\Models\DocPage;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<DocPage>
 */
#[Nullable(['parent', 'archived_at', 'updated_by'])]
class DocPageSummaryResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return DocPage::class;
    }

    /**
     * @param DocPage $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'parent' => $model->parent,
            'title' => $model->title,
            'tags' => DocumentationService::tagsOf($model),
            'version' => $model->version,
            'created_by' => $model->created_by,
            'archived_at' => $model->archived_at,
            'updated_by' => $model->isLoaded('_updated_by') ? $model->_updated_by?->email : null,
            'updated_at' => $model->updated_at,
        ];
    }
}
