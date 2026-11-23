<?php

namespace App\Resources;

use App\Documentation\DocumentationImages;
use App\Models\DocImage;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;

/**
 * @extends ModelResource<DocImage>
 */
class DocImageResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return DocImage::class;
    }

    /**
     * @param DocImage $model
     */
    public function toArray(Model $model): array
    {
        $url = DocumentationImages::urlOf($model);

        return [
            'id' => $model->id,
            'name' => $model->name,
            'type' => $model->type,
            'size' => $model->size,
            'url' => $url,
            'markdown' => '!['.str_replace([']', '['], '', $model->name)."]({$url})",
        ];
    }
}
