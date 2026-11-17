<?php

namespace App\Requests;

use App\Connector\HandlerRules;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class CreateAccessKeyRequest extends Request
{
    public function getRules(): array
    {
        return [
            'name' => Param::string()->withValueCondition(
                fn ($name) => '' !== (string) $name && mb_strlen((string) $name) <= 100,
                '{key} must contain between 1 and 100 characters'
            ),
            'listen' => ListenRequest::listenRule()->optional(),
            'handlers' => HandlerRules::rule()->optional(),
        ];
    }
}
