<?php

namespace App\Integrations;

use Cube\Env\Configuration\ConfigurationElement;

class IntegrationConfiguration extends ConfigurationElement
{
    public function __construct(
        public readonly int $closedPullRequestRetentionDays = 30,
    ) {}
}
