<?php

namespace App\Domain\AccessKey;

use App\Domain\AccessKey\Connector\HandlerRules;
use App\Models\AccessKey;
use App\Models\ConfigDownload;
use App\Models\User;
use App\Models\WorkspaceMember;
use Cube\Data\Bunch;
use Cube\Utils\Identifier;

class AccessKeyService
{
    /**
     * @return Bunch<int,AccessKey> The usable keys of the workspace, newest first
     */
    public function listFor(int $workspace): Bunch
    {
        return AccessKey::select()
            ->where('workspace', $workspace)
            ->where('revoked_at', null)
            ->order('id', 'DESC')
            ->toBunch();
    }

    /**
     * @return array{0: AccessKey, 1: string} The key and its secret, which cannot be read back afterwards
     */
    public const LISTEN_EVERYTHING = ['*'];
    public const MAX_LISTEN_PATTERNS = 20;
    public const LISTEN_PATTERN = '/^[a-z0-9_.*?-]{1,100}$/';

    /**
     * @param list<string> $listen   Patterns of the events sent to the devices using this key
     * @param list<array>  $handlers Handler rules, normalized by `HandlerRules::normalize()`
     */
    public function create(int $workspace, ?int $createdBy, string $name, array $listen = self::LISTEN_EVERYTHING, array $handlers = []): array
    {
        $secret = $this->newSecret();

        $key = (new AccessKey([
            'workspace' => $workspace,
            'created_by' => $createdBy,
            'name' => $name,
            'client_id' => 'twr_'.bin2hex(random_bytes(12)),
            'secret_hash' => Identifier::hash256($secret),
            'listen' => $this->encodeListen($listen),
            'handlers' => HandlerRules::encode($handlers),
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();

        return [$key, $secret];
    }

    public function findUsable(int $workspace, int $id): ?AccessKey
    {
        $key = AccessKey::find($id);

        return $key && $key->workspace === $workspace && !$key->revoked_at ? $key : null;
    }

    /**
     * @param list<string> $listen
     */
    public function setListen(AccessKey $key, array $listen): void
    {
        $key->listen = $this->encodeListen($listen);
        $key->save();
    }

    /**
     * @return list<string>
     */
    public function listenOf(AccessKey $key): array
    {
        $listen = json_decode((string) $key->listen, true);

        return is_array($listen) ? array_values(array_filter($listen, 'is_string')) : [];
    }

    /**
     * `*` matches any sequence of characters, dots included.
     *
     * @param list<string> $listen
     */
    public function listens(array $listen, string $event): bool
    {
        foreach ($listen as $pattern) {
            if (fnmatch($pattern, $event))
                return true;
        }

        return false;
    }

    private function encodeListen(array $listen): string
    {
        return json_encode(array_values(array_unique($listen)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param list<array> $handlers Handler rules, normalized by `HandlerRules::normalize()`
     *
     * @return string The new secret : the configuration files given before no longer connect
     */
    public function setHandlers(AccessKey $key, array $handlers): string
    {
        $key->handlers = HandlerRules::encode($handlers);

        return $this->rotateSecret($key);
    }

    /**
     * @return list<array>
     */
    public function handlersOf(AccessKey $key): array
    {
        return HandlerRules::decode($key->handlers);
    }

    /**
     * Replaces the secret, and drops the download links holding the previous one.
     *
     * @return string The new secret, which cannot be read back afterwards
     */
    public function rotateSecret(AccessKey $key): string
    {
        $secret = $this->newSecret();
        $key->secret_hash = Identifier::hash256($secret);
        $key->save();
        ConfigDownload::delete()->where('access_key', $key->id)->fetch();

        return $secret;
    }

    public function revoke(AccessKey $key): void
    {
        $key->revoked_at = date('Y-m-d H:i:s');
        $key->save();
        ConfigDownload::delete()->where('access_key', $key->id)->fetch();
    }

    /**
     * @return list<AccessKey> The usable keys this member created in the workspace
     */
    public function createdBy(int $workspace, int $user): array
    {
        return AccessKey::select()
            ->where('workspace', $workspace)
            ->where('created_by', $user)
            ->where('revoked_at', null)
            ->fetch();
    }

    /**
     * A key stops working with its creator : once disabled, or no longer a member of the workspace.
     */
    public function creatorAllows(AccessKey $key): bool
    {
        if (null === $key->created_by)
            return true;
        $creator = User::find($key->created_by);

        return $creator
            && null === $creator->disabled_at
            && WorkspaceMember::existsWhere(['user' => $creator->id, 'workspace' => $key->workspace]);
    }

    public function secretMatches(AccessKey $key, #[\SensitiveParameter] string $secret): bool
    {
        // A 256-bit random secret needs no slow hash, and bcrypt would hold the websocket event loop
        return Identifier::matches256($secret, $key->secret_hash);
    }

    public function authenticate(string $clientId, #[\SensitiveParameter] string $secret): ?AccessKey
    {
        $key = AccessKey::findWhere(['client_id' => $clientId]);

        return $key && !$key->revoked_at && $this->secretMatches($key, $secret) && $this->creatorAllows($key) ? $key : null;
    }

    public function markUsed(AccessKey $key): void
    {
        $key->last_used_at = date('Y-m-d H:i:s');
        $key->save();
    }

    private function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
