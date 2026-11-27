<?php

namespace App\Resources;

use App\Models\WebhookCall;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;
use Cube\Data\OpenAPI\Attributes\Nullable;

/**
 * @extends ModelResource<WebhookCall>
 */
#[Nullable(['ip', 'user_agent', 'payload'])]
class WebhookCallResource extends ModelResource
{
    public static function getModelClass(): string
    {
        return WebhookCall::class;
    }

    /**
     * @param WebhookCall $model
     */
    public function toArray(Model $model): array
    {
        return [
            'id' => $model->id,
            // accepted, unauthorized, disabled or invalid
            'status' => $model->status,
            'ip' => $model->ip,
            'user_agent' => $model->user_agent,
            'payload' => $model->payload,
            'created_at' => $model->created_at,
        ];
    }
}
