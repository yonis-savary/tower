<?php

namespace App\Channels;

use App\Models\AccessKey;
use App\Services\AccessKeyService;
use App\Services\HistoryService;
use Cube\Web\Http\Rules\Param;
use Cube\Web\Http\Rules\Rule;
use Cube\Web\Websocket\Channel\Channel;
use Cube\Web\Websocket\Channel\ChannelSubscriber;
use Psr\Http\Message\RequestInterface;
use Ratchet\ConnectionInterface;

/**
 * The connection of each device, authenticated by an access key given in the handshake headers.
 */
class DevicesChannel extends Channel
{
    public const HEADER_CLIENT_ID = 'X-Tower-Client-Id';
    public const HEADER_SECRET = 'X-Tower-Secret';
    public const HEADER_HOSTNAME = 'X-Tower-Hostname';
    public const HEADER_DEVICE_TYPE = 'X-Tower-Device-Type';
    public const DEVICE_TYPES = ['desktop', 'laptop', 'mobile'];
    public const MAX_HEADER_LENGTH = 150;

    /**
     * @var array<int,int> Access key of each connection, by connection id
     */
    private array $accessKeys = [];

    /**
     * @var array<int,string> Why Tower is closing a connection, by connection id
     */
    private array $closeReasons = [];

    public function __construct(
        protected AccessKeyService $keys,
        protected HistoryService $history,
    ) {}

    public function getRoute(): string
    {
        return '/devices';
    }

    public function authorize(array $slugs, RequestInterface $request): ?string
    {
        return $this->authenticate($request) ? null : 'Invalid credentials';
    }

    public function subscribe(string $path, ConnectionInterface $connection, array $slugs = [])
    {
        parent::subscribe($path, $connection, $slugs);

        $key = $this->authenticate($connection->httpRequest);
        $this->accessKeys[$connection->resourceId] = $key->id;
        $this->keys->markUsed($key);

        $uuid = $this->uuid();
        $this->getSubscriber($connection)->send(['type' => 'connection', 'uuid' => $uuid]);
        $device = $this->describe($connection->httpRequest, $key->name, $uuid);
        ConnectedDevices::getInstance()->add($connection->resourceId, $key->workspace, $device);
        $this->history->log($key->workspace, 'device.connected', $device, HistoryService::ORIGIN_DEVICE);
    }

    /**
     * @return array{uuid: string, hostname: ?string, type: string, user_agent: ?string, access_key: string, connected_at: string}
     */
    public function describe(RequestInterface $request, string $accessKey, string $uuid): array
    {
        $type = $request->getHeaderLine(self::HEADER_DEVICE_TYPE);

        return [
            'uuid' => $uuid,
            'hostname' => $this->cleanHeader($request->getHeaderLine(self::HEADER_HOSTNAME)),
            'type' => in_array($type, self::DEVICE_TYPES, true) ? $type : 'unknown',
            'user_agent' => $this->cleanHeader($request->getHeaderLine('User-Agent')),
            'access_key' => $accessKey,
            'connected_at' => date(DATE_ATOM),
        ];
    }

    // Written by the client : invalid UTF-8 would make the JSON sent to the browsers fail
    private function cleanHeader(string $value): ?string
    {
        $clean = trim(mb_substr(preg_replace('/\p{C}/u', '', mb_scrub($value, 'UTF-8')), 0, self::MAX_HEADER_LENGTH));

        return '' === $clean ? null : $clean;
    }

    public function unsubscribe(ConnectionInterface $connection): bool
    {
        $id = $connection->resourceId;
        $reason = $this->closeReasons[$id] ?? 'connection_closed';
        unset($this->accessKeys[$id], $this->closeReasons[$id]);

        if ($removed = ConnectedDevices::getInstance()->remove($id)) {
            $this->history->log($removed['workspace'], 'device.disconnected', [
                'uuid' => $removed['device']['uuid'],
                'hostname' => $removed['device']['hostname'],
                'access_key' => $removed['device']['access_key'],
                'reason' => $reason,
            ], HistoryService::ORIGIN_DEVICE);
        }

        return parent::unsubscribe($connection);
    }

    public function dispatch(string $path, array $data = [])
    {
        if ('event' === ($data['type'] ?? null)) {
            $this->deliverEvent($data);
            return;
        }

        if ('disconnect' !== ($data['type'] ?? null)) {
            parent::dispatch($path, $data);
            return;
        }

        $connections = isset($data['uuid'])
            ? ConnectedDevices::getInstance()->connectionsOf($data['workspace'], $data['uuid'])
            : array_keys($this->accessKeys, $data['access_key'], true);

        foreach ($connections as $connectionId) {
            if (!$subscriber = $this->subscribers[$connectionId] ?? null)
                continue;

            $this->closeReasons[$connectionId] = $data['reason'];
            $subscriber->send(['type' => 'disconnect', 'reason' => $data['reason']]);
            $subscriber->connection->close();
        }
    }

    /**
     * Send an event to every device connected to this workspace.
     *
     * @param int    $id      Its line in the history
     * @param string $payload JSON object
     */
    public function sendEvent(int $workspace, int $id, string $event, string $payload): void
    {
        $this->emit(['type' => 'event', 'workspace' => $workspace, 'id' => $id, 'event' => $event, 'payload' => $payload]);
    }

    /**
     * Only to the devices whose configuration listens to this event. The listened patterns are read
     * at each event : a change made in the front applies without reconnecting.
     */
    private function deliverEvent(array $data): void
    {
        $connected = ConnectedDevices::getInstance();
        $connections = array_filter($connected->connectionsIn($data['workspace']), fn (int $id) => isset($this->subscribers[$id], $this->accessKeys[$id]));
        if (!$connections)
            return;

        $listen = [];
        foreach (AccessKey::select()->whereIn('id', array_unique(array_map(fn (int $id) => $this->accessKeys[$id], $connections)))->fetch() as $key)
            $listen[$key->id] = $this->keys->listenOf($key);

        foreach ($connections as $connectionId) {
            $key = $this->accessKeys[$connectionId];
            if (!$this->keys->listens($listen[$key] ?? [], $data['event']))
                continue;

            $device = $connected->deviceOf($connectionId);
            $delivery = $this->history->logDelivery($data['id'], $device['uuid'], $device['hostname'], $data['payload'], $key);

            $this->subscribers[$connectionId]->send([
                'type' => 'event',
                'id' => $data['id'],
                'delivery' => $delivery->id,
                'workspace' => $data['workspace'],
                'event' => $data['event'],
                'payload' => json_decode($data['payload']),
            ]);
        }
    }

    public function getMessageRule(): ?Rule
    {
        return Param::object([
            'type' => Param::string()->inArray(['response']),
            'delivery' => Param::integer(),
            'status' => Param::string()->inArray(HistoryService::DELIVERY_ANSWERS),
            'exit_code' => Param::integer(nullable: true)->optional(),
            'output' => Param::string(trim: false, nullable: true)->optional(),
        ]);
    }

    /**
     * What a connector answers for a delivery : `running` when its handler starts, then the result.
     */
    public function onMessage(ChannelSubscriber $from, array $data): void
    {
        $key = $this->accessKeys[$from->connection->resourceId] ?? null;
        if (null === $key)
            return;

        $this->history->recordResponse($key, $data['delivery'], $data['status'], $data['exit_code'] ?? null, $data['output'] ?? null);
    }

    /**
     * Close the connection of this device, if it belongs to this workspace.
     */
    public function disconnectDevice(int $workspace, string $uuid, string $reason): void
    {
        $this->emit(['type' => 'disconnect', 'workspace' => $workspace, 'uuid' => $uuid, 'reason' => $reason]);
    }

    /**
     * Close every connection opened with this access key.
     */
    public function disconnectKey(int $accessKey, string $reason): void
    {
        $this->emit(['type' => 'disconnect', 'access_key' => $accessKey, 'reason' => $reason]);
    }

    private function authenticate(RequestInterface $request): ?AccessKey
    {
        return $this->keys->authenticate(
            $request->getHeaderLine(self::HEADER_CLIENT_ID),
            $request->getHeaderLine(self::HEADER_SECRET),
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
