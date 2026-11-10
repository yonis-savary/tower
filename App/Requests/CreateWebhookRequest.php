<?php

namespace App\Requests;

use Cube\Web\Http\Rules\Param;

class CreateWebhookRequest extends WebhookRequest
{
    public function getRules(): array
    {
        return [
            ...parent::getRules(),
            // Secured unless told otherwise ; it cannot change afterwards
            'secured' => Param::boolean()->optional(),
        ];
    }
}
