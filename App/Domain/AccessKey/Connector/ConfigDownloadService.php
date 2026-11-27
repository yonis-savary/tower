<?php

namespace App\Domain\AccessKey\Connector;

use App\Models\AccessKey;
use App\Models\ConfigDownload;
use Cube\Data\Database\Database;
use Cube\Utils\Identifier;

use function Cube\decrypt;
use function Cube\encrypt;

/**
 * Single-use links to download a configuration from a terminal (`curl`), without a session.
 *
 * The link carries the configuration with its secret : it is encrypted with APP_KEY, only the hash of
 * its token is stored, it expires quickly and serves once. Rotating or revoking the key drops its links.
 */
class ConfigDownloadService
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_PYZ = 'pyz';
    public const FORMATS = [self::FORMAT_JSON, self::FORMAT_PYZ];
    public const LIFETIME_MINUTES = 15;

    /**
     * @param array<string,mixed> $config As given by `ConnectorPackager::configFor()`
     *
     * @return array{token: string, expires_at: string} The token, which cannot be read back afterwards
     */
    public function create(AccessKey $key, string $format, #[\SensitiveParameter] array $config): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+'.self::LIFETIME_MINUTES.' minutes'));

        (new ConfigDownload([
            'access_key' => $key->id,
            'token_hash' => Identifier::hash256($token),
            'format' => $format,
            'payload' => encrypt(json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();

        return ['token' => $token, 'expires_at' => date(DATE_ATOM, strtotime($expiresAt))];
    }

    /**
     * Marks the link used before giving its content : two calls at once cannot both get it.
     *
     * @return ?array{format: string, config: array<string,mixed>} Null for an unknown, used or expired link
     */
    public function consume(#[\SensitiveParameter] string $token): ?array
    {
        $link = ConfigDownload::findWhere(['token_hash' => Identifier::hash256($token)]);
        if (!$link)
            return null;

        $now = date('Y-m-d H:i:s');
        $claimed = Database::getInstance()->exec(
            'UPDATE config_download SET used_at = {} WHERE id = {} AND used_at IS NULL AND expires_at > {}',
            [$now, $link->id, $now]
        );
        if (1 !== $claimed)
            return null;

        // The secret is not kept once served
        Database::getInstance()->exec("UPDATE config_download SET payload = '' WHERE id = {}", [$link->id]);

        return ['format' => $link->format, 'config' => json_decode(decrypt($link->payload), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function purgeExpired(): void
    {
        Database::getInstance()->exec('DELETE FROM config_download WHERE expires_at < {} OR used_at IS NOT NULL', [date('Y-m-d H:i:s')]);
    }
}
