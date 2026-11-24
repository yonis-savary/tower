<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\ObjectParam;
use Cube\Web\Http\Rules\Param;

class DeleteAgendaItemRequest extends Request
{
    use TargetsOccurrence;

    public function getRules(): ObjectParam
    {
        return Param::object($this->occurrenceRules());
    }
}
