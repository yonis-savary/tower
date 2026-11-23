<?php

namespace App\Requests;

use App\Requests\Rules\DocPageFields;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class CreateDocPageRequest extends Request
{
    public function getRules(): array
    {
        return [
            'title' => DocPageFields::title(),
            'content' => DocPageFields::content()->optional(),
            'parent' => Param::integer(nullable: true)->optional(),
            'tags' => DocPageFields::tags()->optional(),
        ];
    }
}
