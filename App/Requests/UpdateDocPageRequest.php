<?php

namespace App\Requests;

use App\Requests\Rules\DocPageFields;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class UpdateDocPageRequest extends Request
{
    public function getRules(): array
    {
        return [
            'title' => DocPageFields::title()->optional(),
            'content' => DocPageFields::content()->optional(),
            'tags' => DocPageFields::tags()->optional(),
            'parent' => Param::integer(nullable: true)->optional(),
            'archived' => Param::boolean()->optional(),
            'version' => Param::integer()->optional(),
        ];
    }
}
