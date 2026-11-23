<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class UploadDocImageRequest extends Request
{
    public function getRules(): array
    {
        return [
            'name' => Param::string()->withValueCondition(
                fn ($name) => '' !== (string) $name && mb_strlen((string) $name) <= 255,
                '{key} must contain between 1 and 255 characters'
            ),
            'content' => Param::string(),
        ];
    }
}
