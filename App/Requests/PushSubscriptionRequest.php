<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\Param;

/**
 * A `PushSubscription` of the browser, as `toJSON()` gives it, plus its content encoding.
 */
class PushSubscriptionRequest extends Request
{
    // The push services of the browsers : any other address would make Tower send requests where a user tells it to
    public const PUSH_SERVICE_HOSTS = ['fcm.googleapis.com', '.push.services.mozilla.com', '.push.apple.com', '.notify.windows.com'];

    public static function endpointRule(): Param
    {
        return Param::url()->withValueCondition(
            fn ($endpoint) => strlen((string) $endpoint) <= 500 && self::isPushService((string) $endpoint),
            '{key} must be the https address of a browser push service, 500 characters at most'
        );
    }

    public static function isPushService(string $endpoint): bool
    {
        if ('https' !== parse_url($endpoint, PHP_URL_SCHEME) || null !== parse_url($endpoint, PHP_URL_PORT))
            return false;
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        foreach (self::PUSH_SERVICE_HOSTS as $allowed) {
            if ($host === $allowed || (str_starts_with($allowed, '.') && str_ends_with($host, $allowed)))
                return true;
        }

        return false;
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
