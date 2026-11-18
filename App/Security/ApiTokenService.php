<?php

namespace App\Security;

use App\Models\ApiToken;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Cube\Data\Bunch;

class ApiTokenService
{
    public const SCOPE_READ = 'read';
    public const SCOPE_WRITE = 'write';
    public const SCOPES = [self::SCOPE_READ, self::SCOPE_WRITE];

    private const PREFIX = 'tower_';

    public function __construct(
        protected WorkspaceService $workspaces,
    ) {}

    /**
     * @return array{0: ApiToken, 1: string}
     */
    public function create(int $user, int $workspace, string $name, string $scope, ?int $expiresInDays): array
    {
        $value = self::PREFIX.bin2hex(random_bytes(32));

        $token = (new ApiToken([
            'user' => $user,
            'workspace' => $workspace,
            'name' => $name,
            'token_hash' => $this->hash($value),
            'scope' => $scope,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => null === $expiresInDays ? null : date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days")),
        ]))->save();

        return [$token, $value];
    }

    /**
     * @return Bunch<int,ApiToken>
     */
    public function listFor(int $user, int $workspace): Bunch
    {
        return Bunch::wrap(ApiToken::select()->where('user', $user)->where('workspace', $workspace)->order('id', 'DESC')->fetch());
    }

    /**
     * @return Bunch<int,ApiToken>
     */
    public function listForWorkspace(int $workspace): Bunch
    {
        return Bunch::wrap(ApiToken::select()->where('workspace', $workspace)->order('id', 'ASC')->fetch());
    }

    public function find(int $user, int $workspace, int $id): ?ApiToken
    {
        return ApiToken::findWhere(['id' => $id, 'user' => $user, 'workspace' => $workspace]);
    }

    public function revoke(ApiToken $token): void
    {
        ApiToken::deleteWhere(['id' => $token->id]);
    }

    public function authenticate(#[\SensitiveParameter] string $value): ?ApiToken
    {
        $token = ApiToken::findWhere(['token_hash' => $this->hash($value)]);
        if (!$token)
            return null;

        $now = date('Y-m-d H:i:s');
        if (null !== $token->expires_at && $this->dateOf($token->expires_at) <= $now)
            return null;

        $user = User::find($token->user);
        if (!$user || null !== $user->disabled_at || !$this->workspaces->isMember($user->id, $token->workspace))
            return null;

        $token->last_used_at = $now;

        return $token->save();
    }

    public function isBlocked(ApiToken $token): bool
    {
        return WorkspaceService::MCP_BLOCKED === $this->mcpAccessOf($token);
    }

    public function canWrite(ApiToken $token): bool
    {
        return self::SCOPE_WRITE === $token->scope && WorkspaceService::MCP_WRITE === $this->mcpAccessOf($token);
    }

    private function mcpAccessOf(ApiToken $token): string
    {
        return Workspace::find($token->workspace)->mcp_access;
    }

    private function dateOf(\DateTimeInterface|string $date): string
    {
        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d H:i:s') : $date;
    }

    private function hash(#[\SensitiveParameter] string $value): string
    {
        return hash('sha256', $value);
    }
}
