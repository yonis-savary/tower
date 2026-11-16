<?php

namespace App\Queues;

use App\Services\PushService;
use Cube\Data\Database\Database;
use Cube\Env\Logger\Logger;
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
        $this->ensureDatabase($workspace, $history, $event, $payload);
        $this->notifications->deliver($workspace, $history, $event, $payload);

        return true;
    }

    /**
     * MySQL closes a connection left idle for hours, and it cannot come back : the item goes back
     * to the queue, and the container restarts the worker with a new connection.
     */
    private function ensureDatabase(int $workspace, int $history, string $event, string $payload): void
    {
        try {
            Database::getInstance()->query('SELECT 1');
        } catch (\Throwable $thrown) {
            Logger::getInstance()->logThrowable($thrown);
            $this->push($workspace, $history, $event, $payload);
            exit(1);
        }
    }
}
