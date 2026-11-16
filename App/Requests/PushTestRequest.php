<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

/**
 * The text of the test notification, in the language of the browser that asks for it.
 */
class PushTestRequest extends Request
{
    public function getRules(): array
    {
        return [
            'title' => Param::string()->withValueCondition(fn ($title) => '' !== (string) $title && mb_strlen((string) $title) <= 100, '{key} must contain between 1 and 100 characters'),
            'body' => Param::string()->withValueCondition(fn ($body) => mb_strlen((string) $body) <= 300, '{key} must contain 300 characters at most'),
        ];
    }
}
