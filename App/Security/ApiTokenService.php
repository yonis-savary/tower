<?php

namespace App\Security;

use App\Domain\Workspace\WorkspaceService;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\Workspace;
use Cube\Data\Bunch;
use Cube\Utils\Identifier;

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
        $value = Identifier::prefixed(self::PREFIX);

        $token = (new ApiToken([
            'user' => $user,
            'workspace' => $workspace,
            'name' => $name,
            'token_hash' => Identifier::hash256($value),
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
        return ApiToken::select()
            ->where('user', $user)
            ->where('workspace', $workspace)
            ->order('id', 'DESC')
            ->toBunch();
    }

    /**
     * @return Bunch<int,ApiToken>
     */
    public function listForWorkspace(int $workspace): Bunch
    {
        return ApiToken::select()
            ->where('workspace', $workspace)
            ->order('id', 'ASC')
            ->toBunch();
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
        $token = ApiToken::findWhere(['token_hash' => Identifier::hash256($value)]);
        if (!$token)
            return null;

        $now = new \DateTimeImmutable();
        if (null !== $token->expires_at && $token->expires_at <= $now)
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
}
