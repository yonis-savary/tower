<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class PreconfiguredConnectorRequest extends Request
{
    public function getRules(): array
    {
        return [
            'secret' => Param::string(trim: false),
        ];
    }
}
