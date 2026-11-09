<?php

namespace App\Security;

use Cube\Web\Websocket\Servers\WebsocketConfiguration;

/**
 * Short-lived proof that a browser may follow a workspace : the socket process has no PHP session.
 * Checked without any query, as a slow check would hold every connected client.
 */
class WorkspaceSocketToken
{
    private const LIFETIME_SECONDS = 60;

    public function __construct(
        protected WebsocketConfiguration $configuration,
    ) {}

    public function issue(int $workspace, ?int $now = null): string
    {
        $expiresAt = ($now ?? time()) + self::LIFETIME_SECONDS;

        return $expiresAt.'.'.$this->sign($workspace, $expiresAt);
    }

    public function isValid(int $workspace, string $token, ?int $now = null): bool
    {
        [$expiresAt, $signature] = array_pad(explode('.', $token, 2), 2, '');

        return ctype_digit($expiresAt)
            && (int) $expiresAt >= ($now ?? time())
            && hash_equals($this->sign($workspace, (int) $expiresAt), $signature);
    }

    private function sign(int $workspace, int $expiresAt): string
    {
        return hash_hmac('sha256', "workspace-socket.{$workspace}.{$expiresAt}", (string) $this->configuration->broadcastSecret);
    }
}
