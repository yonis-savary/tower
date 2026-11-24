<?php

namespace App\Requests;

class UpdateAgendaItemRequest extends AgendaItemRequest
{
    use TargetsOccurrence;

    protected function fieldRules(): array
    {
        return [
            ...parent::fieldRules(),
            ...$this->occurrenceRules(),
        ];
    }
}
