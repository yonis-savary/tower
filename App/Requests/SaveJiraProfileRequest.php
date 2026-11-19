<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class SaveJiraProfileRequest extends Request
{
    // The filter is wrapped in Tower's own query, which carries the only ORDER BY
    private const ORDER_BY = '/\border\s+by\b/i';

    public function getRules(): array
    {
        return [
            'name' => Param::string(nullable: true)->withValueCondition(
                fn ($name) => mb_strlen(trim((string) $name)) <= 100,
                '{key} must contain at most 100 characters'
            )->optional(),
            'board' => Param::integer()->withValueCondition(fn ($board) => $board > 0, '{key} must be the id of a Jira board'),
            'jql' => Param::string(nullable: true)->withValueCondition(
                fn ($jql) => mb_strlen(trim((string) $jql)) <= 1000 && 1 !== preg_match(self::ORDER_BY, (string) $jql),
                '{key} must be a JQL condition of at most 1000 characters, without ORDER BY'
            )->optional(),
        ];
    }
}
