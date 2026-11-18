<?php

namespace App\Services;

use Cube\Core\Component;

class HistoryActor
{
    use Component;

    public function __construct(
        public readonly string $origin = HistoryService::ORIGIN_USER,
        public readonly ?string $tokenName = null,
    ) {}
}
