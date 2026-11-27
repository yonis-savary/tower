<?php

namespace App\Push;

use App\Models\PushSubscription;
use App\Requests\PushSubscriptionRequest;
use Cube\Core\Component;
use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends one notification to the push service of a browser, signed with the VAPID keys. Replaced in
 * the tests, which never reach a real push service.
 */
class PushSender
{
    use Component;

    // A notification older than this is of no use : the push service drops it
    public const TTL_SECONDS = 3600;

    // Sent from a request or the queue worker : a push service that hangs must not hold them
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TIMEOUT_SECONDS = 10;

    private ?WebPush $webPush = null;

    public function __construct(
        protected PushConfiguration $configuration,
    ) {}

    /**
     * @param array<string,mixed> $message Read by the service worker of the browser
     */
    public function send(PushSubscription $subscription, array $message): PushResult
    {
        // Saved before the endpoint was restricted to push services : forgotten like an expired one
        if (!PushSubscriptionRequest::isPushService($subscription->endpoint))
            return new PushResult(410);

        $report = $this->webPush()->sendOneNotification(
            new Subscription($subscription->endpoint, $subscription->p256dh, $subscription->auth, $subscription->content_encoding),
            json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $response = $report->getResponse();
        if (!$response)
            return new PushResult(0);

        $retryAfter = $response->getHeaderLine('Retry-After');

        return new PushResult($response->getStatusCode(), ctype_digit($retryAfter) ? (int) $retryAfter : null);
    }

    public function wait(int $seconds): void
    {
        sleep($seconds);
    }

    private function webPush(): WebPush
    {
        return $this->webPush ??= new WebPush(
            ['VAPID' => [
                'subject' => $this->configuration->subject,
                'publicKey' => $this->configuration->publicKey,
                'privateKey' => $this->configuration->privateKey,
            ]],
            ['TTL' => self::TTL_SECONDS, 'urgency' => 'high'],
            new Client(['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS, 'timeout' => self::TIMEOUT_SECONDS]),
        );
    }
}
