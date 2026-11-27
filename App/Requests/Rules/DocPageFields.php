<?php

namespace App\Requests\Rules;

use App\Domain\Documentation\DocumentationService;
use Cube\Web\Http\Rules\ArrayParam;
use Cube\Web\Http\Rules\Param;

class DocPageFields
{
    public static function title(): Param
    {
        return Param::string()->withValueCondition(
            fn ($title) => '' !== (string) $title && mb_strlen((string) $title) <= 255,
            '{key} must contain between 1 and 255 characters'
        );
    }

    public static function content(): Param
    {
        return Param::string(trim: false)->withValueCondition(
            fn ($content) => strlen((string) $content) <= DocumentationService::MAX_CONTENT_BYTES,
            '{key} must not exceed '.DocumentationService::MAX_CONTENT_BYTES.' bytes'
        );
    }

    public static function tags(): ArrayParam
    {
        return Param::array(Param::string()->withValueCondition(
            fn ($tag) => '' !== (string) $tag && mb_strlen((string) $tag) <= DocumentationService::MAX_TAG_LENGTH,
            '{key} must contain between 1 and '.DocumentationService::MAX_TAG_LENGTH.' characters'
        ))->maxSize(DocumentationService::MAX_TAGS);
    }
}
