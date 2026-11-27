<?php

namespace App\Domain\Event;

use App\Channels\DevicesChannel;
use App\Domain\Notification\PushService;

/**
 * Where every event goes once written in the history : to the connected devices, and to the
 * browsers of the members who asked for a notification.
 */
class EventAnnouncer
{
    public function __construct(
        protected DevicesChannel $devices,
        protected PushService $push,
    ) {}

    /**
     * @param int    $history Its line in the history
     * @param string $payload JSON object
     */
    public function announce(int $workspace, int $history, string $event, string $payload): void
    {
        $this->devices->sendEvent($workspace, $history, $event, $payload);
        $this->push->enqueue($workspace, $history, $event, $payload);
    }
}
