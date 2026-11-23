<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class ListDocPagesRequest extends Request
{
    public function getRules(): array
    {
        return [
            'search' => Param::string()->optional(),
            'tag' => Param::string()->optional(),
            'include_archived' => Param::boolean()->optional(),
        ];
    }
}
