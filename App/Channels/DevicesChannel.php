<?php

namespace App\Channels;

use App\Services\AccessKeyService;
use Cube\Web\Websocket\Channel\Channel;
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

    public function __construct(
        protected AccessKeyService $keys,
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
        ConnectedDevices::getInstance()->add(
            $connection->resourceId,
            $key->workspace,
            $this->describe($connection->httpRequest, $key->name, $uuid),
        );
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
        unset($this->accessKeys[$connection->resourceId]);
        ConnectedDevices::getInstance()->remove($connection->resourceId);

        return parent::unsubscribe($connection);
    }

    public function dispatch(string $path, array $data = [])
    {
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

            $subscriber->send(['type' => 'disconnect', 'reason' => $data['reason']]);
            $subscriber->connection->close();
        }
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

    private function authenticate(RequestInterface $request): ?\App\Models\AccessKey
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
