<?php

namespace App\Resources;

use App\Models\DocPage;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<DocPage>
 */
#[Nullable(['parent', 'archived_at', 'updated_by'])]
class DocPageResource extends ModelResource
{
    public function __construct(
        protected DocPageSummaryResource $summary,
    ) {}

    public static function getModelClass(): string
    {
        return DocPage::class;
    }

    /**
     * @param DocPage $model
     */
    public function toArray(Model $model): array
    {
        return [...$this->summary->toArray($model), 'content' => $model->content];
    }
}
