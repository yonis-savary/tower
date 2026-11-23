<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class EditDocPageRequest extends Request
{
    public function getRules(): array
    {
        return [
            'edits' => Param::array(Param::object([
                'old_text' => Param::string(trim: false)->withValueCondition(fn ($text) => '' !== (string) $text, '{key} must not be empty'),
                'new_text' => Param::string(trim: false),
            ]))->minSize(1),
            'version' => Param::integer()->optional(),
        ];
    }
}
