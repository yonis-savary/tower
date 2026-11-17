<?php

namespace App\Requests;

use Cube\Web\Http\Request;

class PushClaimRequest extends Request
{
    public function getRules(): array
    {
        return ['endpoint' => PushSubscriptionRequest::endpointRule()];
    }
}
