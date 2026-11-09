<?php

namespace App\Requests;

use Cube\Web\Http\Rules\Param;

class CreateTaskRequest extends TaskContentRequest
{
    public function getRules(): array
    {
        return [
            ...parent::getRules(),
            'sprint' => Param::integer(nullable: true)->optional(),
        ];
    }
}
