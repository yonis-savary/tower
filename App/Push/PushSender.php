<?php

namespace App\Push;

use App\Models\PushSubscription;
use Cube\Core\Component;
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

    private ?WebPush $webPush = null;

    public function __construct(
        protected PushConfiguration $configuration,
    ) {}

    /**
     * @param array<string,mixed> $message Read by the service worker of the browser
     */
    public function send(PushSubscription $subscription, array $message): PushResult
    {
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
        );
    }
}
