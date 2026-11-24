<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class SearchRequest extends Request
{
    public function getRules(): array
    {
        return [
            'q' => Param::string()->withValueCondition(
                fn ($text) => mb_strlen((string) $text) >= 2 && mb_strlen((string) $text) <= 100,
                '{key} must contain between 2 and 100 characters'
            ),
        ];
    }
}
