<?php

namespace App\Requests;

use Cube\Web\Http\Rules\Param;

class CreateTaskNotesRequest extends TaskNotesRequest
{
    public function getRules(): array
    {
        return [
            ...parent::getRules(),
            'sprint' => Param::integer(nullable: true)->optional(),
        ];
    }
}
