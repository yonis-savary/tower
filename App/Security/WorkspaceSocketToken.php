<?php

namespace App\Security;

use Cube\Utils\SignedPayload;

/**
 * Short-lived proof that a browser may follow a workspace : the socket process has no PHP session.
 * Checked without any query, as a slow check would hold every connected client.
 */
class WorkspaceSocketToken
{
    private const LIFETIME_SECONDS = 60;

    public function issue(int $workspace, ?int $now = null): string
    {
        return SignedPayload::sign($this->payloadOf($workspace), expiresAt: ($now ?? time()) + self::LIFETIME_SECONDS);
    }

    public function isValid(int $workspace, string $token): bool
    {
        return SignedPayload::verify($token) === $this->payloadOf($workspace);
    }

    private function payloadOf(int $workspace): string
    {
        return "workspace-socket.{$workspace}";
    }
}
