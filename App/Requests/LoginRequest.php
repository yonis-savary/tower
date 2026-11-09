<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class LoginRequest extends Request
{
    public function getRules(): array
    {
        return [
            'email' => Param::email()->withTransformer(fn ($email) => is_string($email) ? strtolower($email) : $email),
            'password' => Param::string(trim: false),
        ];
    }
}
