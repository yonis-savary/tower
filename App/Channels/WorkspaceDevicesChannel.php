<?php

namespace App\Channels;

use App\Security\WorkspaceSocketToken;
use Cube\Web\Websocket\Channel\Channel;
use Psr\Http\Message\RequestInterface;
use Ratchet\ConnectionInterface;

/**
 * Live news of a workspace's devices, followed by the web interface.
 */
class WorkspaceDevicesChannel extends Channel
{
    public function __construct(
        protected WorkspaceSocketToken $tokens,
    ) {}

    public function getRoute(): string
    {
        return '/workspaces/{workspace}/devices';
    }

    public function authorize(array $slugs, RequestInterface $request): ?string
    {
        parse_str($request->getUri()->getQuery(), $query);

        return is_string($query['token'] ?? null) && $this->tokens->isValid((int) $slugs['workspace'], $query['token'])
            ? null
            : 'Invalid token';
    }

    public function subscribe(string $path, ConnectionInterface $connection, array $slugs = [])
    {
        parent::subscribe($path, $connection, $slugs);

        // Also tells the browser its subscription was accepted : a refused socket opens too
        $this->getSubscriber($connection)->send([
            'type' => 'devices.list',
            'devices' => ConnectedDevices::getInstance()->listFor((int) $slugs['workspace']),
        ]);
    }
}
