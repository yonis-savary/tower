<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class SaveSprintLinkRequest extends Request
{
    public function getRules(): array
    {
        return [
            // http(s) only : the address becomes the href of a link, where javascript: would run
            'url' => Param::string()->withValueCondition(
                fn ($url) => mb_strlen((string) $url) <= 2000
                    && false !== filter_var($url, FILTER_VALIDATE_URL)
                    && in_array(strtolower((string) parse_url((string) $url, PHP_URL_SCHEME)), ['http', 'https'], true),
                '{key} must be an http or https address of at most 2000 characters'
            ),
            'label' => Param::string(nullable: true)->withValueCondition(
                fn ($label) => mb_strlen((string) $label) <= 100,
                '{key} must contain at most 100 characters'
            )->optional(),
        ];
    }
}
