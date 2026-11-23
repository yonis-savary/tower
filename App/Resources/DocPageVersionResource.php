<?php

namespace App\Resources;

use App\Models\DocPageVersion;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<DocPageVersion>
 */
#[Nullable(['author', 'token'])]
class DocPageVersionResource extends ModelResource
{
    public function __construct(
        protected DocPageVersionSummaryResource $summary,
    ) {}

    public static function getModelClass(): string
    {
        return DocPageVersion::class;
    }

    /**
     * @param DocPageVersion $model
     */
    public function toArray(Model $model): array
    {
        return [...$this->summary->toArray($model), 'content' => $model->content];
    }
}
