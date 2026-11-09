<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class TaskContentRequest extends Request
{
    private const MAX_DESCRIPTION = 20000;

    public function getRules(): array
    {
        return [
            'title' => Param::string()->withValueCondition(
                fn ($title) => '' !== (string) $title && mb_strlen((string) $title) <= 255,
                '{key} must contain between 1 and 255 characters'
            ),
            'description' => Param::string()->withValueCondition(
                fn ($description) => mb_strlen((string) $description) <= self::MAX_DESCRIPTION,
                '{key} is too long'
            )->optional(),
        ];
    }
}
