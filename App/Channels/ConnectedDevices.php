<?php

namespace App\Channels;

use Cube\Core\Component;

/**
 * The devices connected to this websocket process, and the live list sent to the web interface.
 *
 * @phpstan-type Device array{uuid: string, hostname: ?string, type: string, user_agent: ?string, access_key: string, connected_at: string}
 */
class ConnectedDevices
{
    use Component;

    /**
     * @var array<int,int> Workspace of each connection, by connection id
     */
    private array $workspaces = [];

    /**
     * @var array<int,Device> Description of each connection, by connection id
     */
    private array $devices = [];

    public function __construct(
        protected WorkspaceDevicesChannel $channel,
    ) {}

    /**
     * @param Device $device
     */
    public function add(int $connection, int $workspace, array $device): void
    {
        $this->workspaces[$connection] = $workspace;
        $this->devices[$connection] = $device;
        $this->publish($workspace);
    }

    public function remove(int $connection): void
    {
        if (null === $workspace = $this->workspaces[$connection] ?? null)
            return;

        unset($this->workspaces[$connection], $this->devices[$connection]);
        $this->publish($workspace);
    }

    /**
     * @return list<Device> In connection order
     */
    public function listFor(int $workspace): array
    {
        return array_values(array_intersect_key($this->devices, array_flip(array_keys($this->workspaces, $workspace, true))));
    }

    /**
     * @return list<int> The connection of this device, none if it is gone or in another workspace
     */
    public function connectionsOf(int $workspace, string $uuid): array
    {
        return array_keys(array_filter(
            $this->devices,
            fn (array $device, int $connection) => $device['uuid'] === $uuid && $this->workspaces[$connection] === $workspace,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function publish(int $workspace): void
    {
        $this->channel->emit(['type' => 'devices.list', 'devices' => $this->listFor($workspace)], ['workspace' => $workspace]);
    }
}
