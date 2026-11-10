<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class RegisterRequest extends Request
{
    private const PASSWORD_MIN_LENGTH = 8;
    private const PASSWORD_MAX_BYTES = 72; // bcrypt silently ignores anything beyond

    public function getRules(): array
    {
        return [
            'email' => Param::email()->withTransformer(fn ($email) => is_string($email) ? strtolower($email) : $email),
            'password' => self::newPassword(),
        ];
    }

    public static function newPassword(): Param
    {
        return Param::string(trim: false)->withValueCondition(
            fn ($password) => mb_strlen((string) $password) >= self::PASSWORD_MIN_LENGTH
                && strlen((string) $password) <= self::PASSWORD_MAX_BYTES,
            '{key} must contain at least 8 characters and at most 72 bytes'
        );
    }
}
