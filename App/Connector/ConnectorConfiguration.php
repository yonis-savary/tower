<?php

namespace App\Connector;

use Cube\Env\Configuration\ConfigurationElement;

class ConnectorConfiguration extends ConfigurationElement
{
    /**
     * @param string $archivePath        Built into the image, see Docker/Dockerfile.php
     * @param string $handlerArchivePath Built into the image, see Docker/Dockerfile.php
     * @param bool   $allowNextEvents    Whether a connector may answer with the `next` events to trigger
     */
    public function __construct(
        public readonly string $archivePath = '/opt/tower/tower-connector.pyz',
        public readonly string $handlerArchivePath = '/opt/tower/tower-handler.pyz',
        public readonly bool $allowNextEvents = true,
    ) {}
}
