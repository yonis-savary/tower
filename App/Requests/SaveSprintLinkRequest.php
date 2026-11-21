<?php

namespace App\Requests;

use App\Requests\Rules\WebAddress;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class SaveSprintLinkRequest extends Request
{
    public function getRules(): array
    {
        return [
            'url' => WebAddress::param(),
            'label' => Param::string(nullable: true)->withValueCondition(
                fn ($label) => mb_strlen((string) $label) <= 100,
                '{key} must contain at most 100 characters'
            )->optional(),
        ];
    }
}
