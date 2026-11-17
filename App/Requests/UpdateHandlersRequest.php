<?php

namespace App\Requests;

use App\Connector\HandlerRules;
use Cube\Web\Http\Request;

class UpdateHandlersRequest extends Request
{
    public function getRules(): array
    {
        return [
            'handlers' => HandlerRules::rule(),
        ];
    }
}
