<?php

namespace App\Queues;

use App\Domain\Notification\PushService;
use Cube\Queue\Queue;

/**
 * Sends the push notifications of each event, out of the request, the scheduler tick or the
 * websocket loop that produced it : a slow push service delays none of them.
 */
class PushQueue extends Queue
{
    public function __construct(
        protected PushService $notifications,
    ) {}

    /**
     * @param string $payload JSON object
     */
    public function __invoke(int $workspace, int $history, string $event, string $payload): bool
    {
        $this->notifications->deliver($workspace, $history, $event, $payload);

        return true;
    }
}
