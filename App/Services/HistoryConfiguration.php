<?php

namespace App\Services;

use Cube\Env\Configuration\ConfigurationElement;

class HistoryConfiguration extends ConfigurationElement
{
    public function __construct(
        public readonly int $retentionDays = 31,
    ) {}
}
