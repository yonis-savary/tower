<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class ChangePasswordRequest extends Request
{
    public function getRules(): array
    {
        return [
            'current_password' => Param::string(trim: false),
            'new_password' => RegisterRequest::newPassword(),
        ];
    }
}
