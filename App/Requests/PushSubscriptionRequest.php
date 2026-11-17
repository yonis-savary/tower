<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

/**
 * A `PushSubscription` of the browser, as `toJSON()` gives it, plus its content encoding.
 */
class PushSubscriptionRequest extends Request
{
    public static function endpointRule(): Param
    {
        return Param::url()->withValueCondition(
            fn ($endpoint) => strlen((string) $endpoint) <= 500 && str_starts_with((string) $endpoint, 'https://'),
            '{key} must be an https address of 500 characters at most'
        );
    }

    public function getRules(): array
    {
        return [
            'endpoint' => self::endpointRule(),
            'keys' => Param::object([
                'p256dh' => Param::string()->withValueCondition(
                    fn ($key) => 1 === preg_match('/^[A-Za-z0-9_-]{1,200}={0,2}$/', (string) $key),
                    '{key} must be a base64url key'
                ),
                'auth' => Param::string()->withValueCondition(
                    fn ($key) => 1 === preg_match('/^[A-Za-z0-9_-]{1,100}={0,2}$/', (string) $key),
                    '{key} must be a base64url key'
                ),
            ]),
            'content_encoding' => Param::string()->inArray(['aes128gcm', 'aesgcm']),
        ];
    }
}
