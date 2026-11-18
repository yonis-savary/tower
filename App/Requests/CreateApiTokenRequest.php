<?php

namespace App\Requests;

use App\Security\ApiTokenService;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class CreateApiTokenRequest extends Request
{
    public const MAX_DAYS = 3650;

    public function getRules(): array
    {
        return [
            'name' => Param::string()->withValueCondition(
                fn ($name) => '' !== (string) $name && mb_strlen((string) $name) <= 100,
                '{key} must contain between 1 and 100 characters'
            ),
            'scope' => Param::string()->inArray(ApiTokenService::SCOPES),
            'expires_in_days' => Param::integer(nullable: true)->isBetween(1, self::MAX_DAYS)->optional(),
        ];
    }
}
