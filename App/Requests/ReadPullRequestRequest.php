<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class ReadPullRequestRequest extends Request
{
    public function getRules(): array
    {
        return [
            'unresolved' => Param::boolean()->optional(),
        ];
    }
}
