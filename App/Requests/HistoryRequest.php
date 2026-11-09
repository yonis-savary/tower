<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class HistoryRequest extends Request
{
    public function getRules(): array
    {
        return [
            'before' => Param::integer()->optional(),
        ];
    }
}
