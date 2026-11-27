<?php

namespace App\Domain\Workspace;

use App\Channels\WorkspaceDevicesChannel;
use Cube\Core\Component;

/**
 * Tells the browsers following a workspace that some of its data changed, so that they load it
 * again. A signal carries no data : the browser reads it through the API, with its own rights.
 */
class WorkspaceSignals
{
    use Component;

    /** A line was added to the history : `history_type`, `history`, `origin`, and for an event `event` and its webhook or schedule as `source` */
    public const HISTORY_CREATED = 'history.created';
    /** A device was sent an event, or answered it : `history` (the line of the event), `device` (its connection) and `status` */
    public const DELIVERY_UPDATED = 'delivery.updated';
    /** A call to a webhook was refused : `webhook` */
    public const WEBHOOK_REFUSED = 'webhook.refused';
    /** An agent called a tool through an API token : `token`, `tool`, `ok` */
    public const MCP_CALLED = 'mcp.called';
    public const SIGNALS = [self::HISTORY_CREATED, self::DELIVERY_UPDATED, self::WEBHOOK_REFUSED, self::MCP_CALLED];

    public function __construct(
        protected WorkspaceDevicesChannel $channel,
    ) {}

    /**
     * @param array<string,scalar|null> $details
     */
    public function send(int $workspace, string $signal, array $details = []): void
    {
        $this->channel->emit(['type' => $signal, ...$details], ['workspace' => $workspace]);
    }
}
