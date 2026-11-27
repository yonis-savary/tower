<?php

namespace App\Requests;

use App\Domain\Webhook\WebhookService;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class WebhookRequest extends Request
{
    public function getRules(): array
    {
        return [
            'name' => Param::string()->withValueCondition(
                fn ($name) => '' !== (string) $name && mb_strlen((string) $name) <= 100,
                '{key} must contain between 1 and 100 characters'
            ),
            'events' => Param::array(Param::string())->withValueCondition(
                fn ($events) => is_array($events)
                    && count($events) >= 1
                    && count($events) <= WebhookService::MAX_EVENTS
                    && [] === array_filter($events, fn ($event) => !is_string($event) || strlen($event) > 100 || 1 !== preg_match(WebhookService::EVENT_PATTERN, $event)),
                '{key} must list between 1 and 20 event types, lowercase words separated by dots'
            ),
        ];
    }
}
