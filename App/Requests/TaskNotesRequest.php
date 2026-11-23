<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class TaskNotesRequest extends Request
{
    public function getRules(): array
    {
        return [
            // Not trimmed : the indentation of the first line is part of the syntax
            'text' => Param::string(trim: false),
        ];
    }
}
