<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class WorkspaceNameRequest extends Request
{
    private const MAX_LENGTH = 100;

    public function getRules(): array
    {
        return [
            'name' => Param::string()->withValueCondition(
                fn ($name) => '' !== trim((string) $name) && mb_strlen(trim((string) $name)) <= self::MAX_LENGTH,
                '{key} must be between 1 and '.self::MAX_LENGTH.' characters'
            ),
        ];
    }
}
