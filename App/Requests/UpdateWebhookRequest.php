<?php

namespace App\Requests;

use Cube\Web\Http\Rules\Param;

class UpdateWebhookRequest extends WebhookRequest
{
    public function getRules(): array
    {
        return [
            ...parent::getRules(),
            'enabled' => Param::boolean(nullable: false),
        ];
    }
}
