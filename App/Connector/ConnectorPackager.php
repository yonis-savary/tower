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
     * @return array{tower_url: string, client_id: string, secret: string, handlers?: \stdClass} The handlers
     *                                                                                          edited in Tower, if there are any
     */
    public function configFor(AccessKey $key, #[\SensitiveParameter] string $secret): array
    {
        $config = [
            'tower_url' => BroadcastConfiguration::resolve()->getWebsocketOrigin().$this->channel->path(),
            'client_id' => $key->client_id,
            'secret' => $secret,
        ];

        $handlers = HandlerRules::toConnector(HandlerRules::decode($key->handlers));

        return $handlers ? [...$config, 'handlers' => $handlers] : $config;
    }

    /**
     * The configuration, as written in `tower-config.json` and inside a preconfigured connector.
     */
    public function encode(#[\SensitiveParameter] array $config): string
    {
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $config
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

            $archive->addFromString(self::EMBEDDED_CONFIG, $this->encode($config));
            $archive->close();

            return file_get_contents($copy);
        } finally {
            unlink($copy);
        }
    }
}
