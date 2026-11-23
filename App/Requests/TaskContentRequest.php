<?php

namespace App\Requests;

use App\Requests\Rules\WebAddress;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class TaskContentRequest extends Request
{
    public const MAX_TITLE = 255;
    public const MAX_DESCRIPTION = 20000;

    public function getRules(): array
    {
        return [
            'title' => Param::string()->withValueCondition(
                fn ($title) => '' !== (string) $title && mb_strlen((string) $title) <= self::MAX_TITLE,
                '{key} must contain between 1 and 255 characters'
            ),
            'description' => Param::string()->withValueCondition(
                fn ($description) => mb_strlen((string) $description) <= self::MAX_DESCRIPTION,
                '{key} is too long'
            )->optional(),
            // Left out, an update keeps the current link; null removes it
            'link' => WebAddress::param(nullable: true)->optional(),
        ];
    }
}
