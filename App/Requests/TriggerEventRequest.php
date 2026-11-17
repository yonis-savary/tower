<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

class TriggerEventRequest extends Request
{
    private const MAX_PAYLOAD_BYTES = 16384;

    public function getRules(): array
    {
        return self::eventRules();
    }

    public static function eventRules(): array
    {
        return [
            'type' => self::typeRule(),
            // Kept as text : decoding it would turn an empty object into an empty list
            'payload' => Param::string()->withValueCondition(
                fn ($payload) => strlen((string) $payload) <= self::MAX_PAYLOAD_BYTES && json_decode((string) $payload) instanceof \stdClass,
                '{key} must be a JSON object of 16 KiB at most'
            ),
        ];
    }

    public static function typeRule(): Param
    {
        return Param::string()->withValueCondition(
            fn ($type) => strlen((string) $type) <= 100 && 1 === preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/', (string) $type),
            '{key} must be lowercase words separated by dots, 100 characters at most'
        );
    }

    /**
     * The payload as a decoded JSON object, as a connector sends it inside its own message.
     */
    public static function objectPayloadRule(): Param
    {
        return (new Param())->withValueCondition(
            fn ($payload) => is_array($payload) && ([] === $payload || !array_is_list($payload)) && strlen((string) json_encode($payload)) <= self::MAX_PAYLOAD_BYTES,
            '{key} must be a JSON object of 16 KiB at most'
        );
    }
}
