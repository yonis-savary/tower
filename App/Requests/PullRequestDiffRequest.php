<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class PullRequestDiffRequest extends Request
{
    public function getRules(): array
    {
        return [
            'path' => Param::string()->optional(),
        ];
    }
}
