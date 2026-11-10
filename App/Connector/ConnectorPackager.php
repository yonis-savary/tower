<?php

namespace App\Connector;

use App\Channels\DevicesChannel;
use App\Models\AccessKey;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class ConnectorPackager
{
    private const EMBEDDED_CONFIG = 'tower_config.json';

    public function __construct(
        protected ConnectorConfiguration $configuration,
        protected DevicesChannel $channel,
    ) {}

    public function isAvailable(): bool
    {
        return is_file($this->configuration->archivePath);
    }

    public function archivePath(): string
    {
        return $this->configuration->archivePath;
    }

    public function isHandlerAvailable(): bool
    {
        return is_file($this->configuration->handlerArchivePath);
    }

    public function handlerArchivePath(): string
    {
        return $this->configuration->handlerArchivePath;
    }

    /**
     * @return array{tower_url: string, client_id: string, secret: string}
     */
    public function configFor(AccessKey $key, #[\SensitiveParameter] string $secret): array
    {
        return [
            'tower_url' => BroadcastConfiguration::resolve()->getWebsocketOrigin().$this->channel->path(),
            'client_id' => $key->client_id,
            'secret' => $secret,
        ];
    }

    /**
     * @param array<string,string> $config
     *
     * @return string The bytes of an archive that needs no configuration file
     */
    public function withEmbeddedConfig(#[\SensitiveParameter] array $config): string
    {
        $copy = tempnam(sys_get_temp_dir(), 'tower-connector');

        try {
            copy($this->configuration->archivePath, $copy);

            $archive = new \ZipArchive();
            if (true !== $archive->open($copy))
                throw new \RuntimeException('Could not open the connector archive');

            $archive->addFromString(self::EMBEDDED_CONFIG, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $archive->close();

            return file_get_contents($copy);
        } finally {
            unlink($copy);
        }
    }
}
