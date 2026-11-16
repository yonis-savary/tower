<?php

namespace App\Requests;

use Cube\Web\Http\Request;

/**
 * The text a rule would give for an example event.
 */
class NotificationPreviewRequest extends Request
{
    public function getRules(): array
    {
        return [
            'title' => NotificationRuleRequest::textRule(),
            'body' => NotificationRuleRequest::textRule(),
            ...TriggerEventRequest::eventRules(),
        ];
    }
}
