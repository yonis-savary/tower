<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class TransitionJiraIssueRequest extends Request
{
    public function getRules(): array
    {
        return [
            'transition' => Param::string()->withValueCondition(
                fn ($transition) => 1 === preg_match('/^\d+$/', (string) $transition),
                '{key} must be the id of a Jira transition'
            ),
        ];
    }
}
