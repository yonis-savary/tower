<?php

namespace App\Requests;

use App\Services\AccessKeyService;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;
use Cube\Web\Http\Rules\Rule;

class ListenRequest extends Request
{
    public static function listenRule(): Rule
    {
        return Param::array(Param::string())->withValueCondition(
            fn ($listen) => is_array($listen)
                && count($listen) <= AccessKeyService::MAX_LISTEN_PATTERNS
                && [] === array_filter($listen, fn ($pattern) => !is_string($pattern) || 1 !== preg_match(AccessKeyService::LISTEN_PATTERN, $pattern)),
            '{key} must list up to 20 event patterns, made of lowercase letters, digits, dots, dashes, underscores, * and ?'
        );
    }

    public function getRules(): array
    {
        return [
            'listen' => self::listenRule(),
        ];
    }
}
