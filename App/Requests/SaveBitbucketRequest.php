<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class SaveBitbucketRequest extends Request
{
    private const SLUG = '/^[A-Za-z0-9._-]+$/';

    public function getRules(): array
    {
        return [
            'workspace' => Param::string()->withValueCondition(
                fn ($workspace) => 1 === preg_match(self::SLUG, (string) $workspace),
                '{key} must be the slug of a Bitbucket workspace'
            ),
            'email' => Param::email(),
            'token' => Param::string()->optional(),
        ];
    }
}
