<?php

namespace App\Requests;

use App\Domain\Integrations\PullRequestData;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class SearchPullRequestsRequest extends Request
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 50;

    public function getRules(): array
    {
        return [
            'branch' => Param::string()->optional(),
            'state' => Param::string()->inArray(PullRequestData::STATES)->optional(),
            'limit' => Param::integer()->isBetween(1, self::MAX_LIMIT)->optional(),
        ];
    }
}
