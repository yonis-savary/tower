<?php

namespace App\Push;

/**
 * The answer of a push service to one notification.
 */
class PushResult
{
    public function __construct(
        // 0 when the push service could not be reached
        public readonly int $status,
        // Seconds asked by `Retry-After`, if any
        public readonly ?int $retryAfter = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The browser dropped its subscription : it will never accept a notification again.
     */
    public function isGone(): bool
    {
        return 404 === $this->status || 410 === $this->status;
    }

    public function isRetryable(): bool
    {
        return 0 === $this->status || 429 === $this->status || $this->status >= 500;
    }
}
