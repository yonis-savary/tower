<?php

namespace App\Requests\Rules;

use Cube\Web\Http\Rules\Param;

class HexColor
{
    public static function param(): Param
    {
        return Param::string(nullable: true)->withValueCondition(
            fn ($color) => null === $color || 1 === preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color),
            '{key} must be a color like #3b82f6, or null'
        );
    }
}
