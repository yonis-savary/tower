<?php

namespace App\Connector;

use Cube\Env\Configuration\ConfigurationElement;

class ConnectorConfiguration extends ConfigurationElement
{
    /**
     * @param string $archivePath Built into the image, see Docker/Dockerfile.php
     */
    public function __construct(
        public readonly string $archivePath = '/opt/tower/tower-connector.pyz',
    ) {}
}
