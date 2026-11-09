<?php

namespace App\Services;

use App\Models\AccessKey;
use Cube\Data\Bunch;

class AccessKeyService
{
    /**
     * @return Bunch<int,AccessKey> The usable keys of the workspace, newest first
     */
    public function listFor(int $workspace): Bunch
    {
        return Bunch::wrap(
            AccessKey::select()
                ->where('workspace', $workspace)
                ->where('revoked_at', null)
                ->order('id', 'DESC')
                ->fetch()
        );
    }

    /**
     * @return array{0: AccessKey, 1: string} The key and its secret, which cannot be read back afterwards
     */
    public function create(int $workspace, string $name): array
    {
        $secret = bin2hex(random_bytes(32));

        // created_at as a string : generated models type a DATETIME column as DATE, which drops the time
        $key = (new AccessKey([
            'workspace' => $workspace,
            'name' => $name,
            'client_id' => 'twr_'.bin2hex(random_bytes(12)),
            'secret_hash' => $this->hash($secret),
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();

        return [$key, $secret];
    }

    public function findUsable(int $workspace, int $id): ?AccessKey
    {
        $key = AccessKey::find($id);

        return $key && $key->workspace === $workspace && !$key->revoked_at ? $key : null;
    }

    public function revoke(AccessKey $key): void
    {
        $key->revoked_at = date('Y-m-d H:i:s');
        $key->save();
    }

    public function secretMatches(AccessKey $key, #[\SensitiveParameter] string $secret): bool
    {
        return hash_equals($key->secret_hash, $this->hash($secret));
    }

    public function authenticate(string $clientId, #[\SensitiveParameter] string $secret): ?AccessKey
    {
        $key = AccessKey::findWhere(['client_id' => $clientId]);

        return $key && !$key->revoked_at && $this->secretMatches($key, $secret) ? $key : null;
    }

    public function markUsed(AccessKey $key): void
    {
        $key->last_used_at = date('Y-m-d H:i:s');
        $key->save();
    }

    // A 256-bit random secret needs no slow hash, and bcrypt would hold the websocket event loop
    private function hash(#[\SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }
}
