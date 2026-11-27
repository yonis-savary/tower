<?php

namespace App\Resources;

use App\Domain\Notification\PushService;
use App\Models\NotificationRule;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<NotificationRule>
 */
#[Nullable(['title', 'body'])]
class NotificationRuleResource extends ModelResource
{
    public function __construct(
        protected PushService $push,
    ) {}

    public static function getModelClass(): string
    {
        return NotificationRule::class;
    }

    /**
     * @param NotificationRule $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'listen' => $this->push->listenOf($model),
            'title' => $model->title,
            'body' => $model->body,
            'enabled' => (bool) $model->enabled,
            'created_at' => $model->created_at,
        ];
    }
}
