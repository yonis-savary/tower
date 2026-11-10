<?php

namespace App\Integrations;

final class IntegrationChange
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        public readonly string $type,
        public readonly array $details = [],
    ) {}
}
