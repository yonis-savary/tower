<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class SaveJiraRequest extends Request
{
    // Jira Cloud only : the token is sent to this host, which must not be any address a user types
    private const SITE = '/^https:\/\/[a-z0-9][a-z0-9-]*\.atlassian\.net$/';

    public function getRules(): array
    {
        return [
            'site' => Param::string()->withValueCondition(
                fn ($site) => 1 === preg_match(self::SITE, (string) $site),
                '{key} must be the address of a Jira Cloud site, like https://my-team.atlassian.net'
            ),
            'email' => Param::email(),
            'token' => Param::string()->optional(),
        ];
    }
}
