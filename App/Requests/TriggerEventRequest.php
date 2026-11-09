<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class TriggerEventRequest extends Request
{
    private const MAX_PAYLOAD_BYTES = 16384;

    public function getRules(): array
    {
        return [
            'type' => Param::string()->withValueCondition(
                fn ($type) => strlen((string) $type) <= 100 && 1 === preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/', (string) $type),
                '{key} must be lowercase words separated by dots, 100 characters at most'
            ),
            // Kept as text : decoding it would turn an empty object into an empty list
            'payload' => Param::string()->withValueCondition(
                fn ($payload) => strlen((string) $payload) <= self::MAX_PAYLOAD_BYTES && json_decode((string) $payload) instanceof \stdClass,
                '{key} must be a JSON object of 16 KiB at most'
            ),
        ];
    }
}
