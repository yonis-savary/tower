<?php

namespace App\Resources;

use App\Domain\Documentation\DocumentationService;
use App\Models\DocPageVersion;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<DocPageVersion>
 */
#[Nullable(['author', 'token'])]
class DocPageVersionSummaryResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return DocPageVersion::class;
    }

    /**
     * @param DocPageVersion $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'page' => $model->page,
            'version' => $model->version,
            'title' => $model->title,
            'tags' => DocumentationService::tagsOf($model),
            'author' => $model->isLoaded('_user') ? $model->_user?->email : null,
            'token' => $model->token,
            'created_at' => $model->created_at,
        ];
    }
}
