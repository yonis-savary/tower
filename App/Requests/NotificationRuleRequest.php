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
     * A raw value, or the dotted path of a key of the payload. Null or missing : the default text.
     */
    public static function textRule(): Rule
    {
        return Param::object([
            'mode' => Param::string()->inArray(PushService::TEXT_MODES),
            'value' => Param::string()->withValueCondition(
                fn ($value) => '' !== (string) $value && mb_strlen((string) $value) <= 200,
                '{key} must contain between 1 and 200 characters'
            ),
        ], nullable: true)->optional();
    }
}
