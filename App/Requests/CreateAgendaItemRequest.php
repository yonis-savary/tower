<?php

namespace App\Requests;

use Cube\Web\Http\Rules\Param;

class CreateAgendaItemRequest extends AgendaItemRequest
{
    protected function fieldRules(): array
    {
        return [
            ...parent::fieldRules(),
            'workspace' => Param::integer()->optional(),
        ];
    }
}
