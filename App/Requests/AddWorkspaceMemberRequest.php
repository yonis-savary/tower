<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class AddWorkspaceMemberRequest extends Request
{
    public function getRules(): array
    {
        return [
            'email' => Param::email(),
        ];
    }
}
