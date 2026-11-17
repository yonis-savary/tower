<?php

namespace App\Requests;

use App\Services\PushService;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;
use Cube\Web\Http\Rules\Rule;

class NotificationRuleRequest extends Request
{
    public function getRules(): array
    {
        return [
            'name' => Param::string()->withValueCondition(
                fn ($name) => '' !== (string) $name && mb_strlen((string) $name) <= 100,
                '{key} must contain between 1 and 100 characters'
            ),
            'listen' => ListenRequest::listenRule()->withValueCondition(
                fn ($listen) => is_array($listen) && [] !== $listen,
                '{key} must list at least one event pattern'
            ),
            'title' => self::textRule(),
            'body' => self::textRule(),
        ];
    }

    /**
     * A template with placeholders such as {payload.title}. Null, missing or empty : the default text.
     */
    public static function textRule(): Rule
    {
        return Param::string(nullable: true)->withValueCondition(
            fn ($template) => null === $template || mb_strlen((string) $template) <= PushService::MAX_TEMPLATE_LENGTH,
            '{key} must contain '.PushService::MAX_TEMPLATE_LENGTH.' characters at most'
        )->optional();
    }
}
