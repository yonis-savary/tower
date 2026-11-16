<?php

namespace App\Requests;

use Cube\Web\Http\Rules\Param;

class UpdateScheduledEventRequest extends ScheduledEventRequest
{
    protected function fieldRules(): array
    {
        return [
            ...parent::fieldRules(),
            'enabled' => Param::boolean(nullable: false),
        ];
    }
}
