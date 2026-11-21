<?php

namespace App\Requests\Rules;

use Cube\Web\Http\Rules\Param;

class WebAddress
{
    public const MAX_LENGTH = 2000;

    /**
     * An http(s) address : it becomes the href of a link, where javascript: would run.
     */
    public static function param(bool $nullable = false): Param
    {
        return Param::string(nullable: $nullable)->withValueCondition(
            fn ($url) => mb_strlen((string) $url) <= self::MAX_LENGTH
                && false !== filter_var($url, FILTER_VALIDATE_URL)
                && in_array(strtolower((string) parse_url((string) $url, PHP_URL_SCHEME)), ['http', 'https'], true),
            '{key} must be an http or https address of at most ' . self::MAX_LENGTH . ' characters'
        );
    }
}
