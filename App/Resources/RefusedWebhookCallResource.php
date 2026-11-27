<?php

namespace App\Resources;

use App\Models\WebhookCall;
use Cube\Data\Models\Model;
use Cube\Data\Models\ModelResource;

/**
 * A refused call, with the name of its webhook, which must be loaded.
 *
 * @extends ModelResource<WebhookCall>
 */
class RefusedWebhookCallResource extends ModelResource
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
            'webhook' => $model->webhook,
            // Empty in the OpenAPI example, made without relations
            'webhook_name' => $model->isLoaded('_webhook') ? $model->_webhook->name : '',
            // unauthorized, disabled or invalid
            'status' => $model->status,
            'created_at' => $model->created_at,
        ];
    }
}
