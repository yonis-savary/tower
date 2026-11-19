<?php

namespace App\Requests;

use App\Integrations\Jira\JiraIssueData;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class ListJiraIssuesRequest extends Request
{
    public function getRules(): array
    {
        return [
            'profile' => Param::integer()->optional(),
            'mine' => Param::boolean()->optional(),
            'status_category' => Param::string()->inArray(JiraIssueData::CATEGORIES)->optional(),
        ];
    }
}
