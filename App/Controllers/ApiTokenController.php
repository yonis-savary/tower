<?php

namespace App\Controllers;

use App\Middlewares\IsAuthenticated;
use App\Requests\CreateApiTokenRequest;
use App\Resources\ApiTokenResource;
use App\Security\ApiTokenService;
use App\Services\HistoryService;
use App\Services\WorkspaceService;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\NoContentResponse;
use Cube\Data\OpenAPI\Attributes\ObjectResponse;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Data\OpenAPI\Attributes\ResourceResponse;
use Cube\Security\Authentication;
use Cube\Web\Controller;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

use function App\currentWorkspace;
use function App\currentWorkspaceId;

class ApiTokenController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected ApiTokenService $tokens,
        protected HistoryService $history,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/tokens', [self::class, 'list']),
            Route::get('/tokens/workspace', [self::class, 'listWorkspace']),
            Route::post('/tokens', [self::class, 'create']),
            Route::delete('/tokens/{int:token}', [self::class, 'revoke']),
        ]);
    }

    #[Endpoint('List API tokens', 'The API tokens of the user for the current workspace, newest first', 'listApiTokens')]
    #[ResourceResponse(ApiTokenResource::class, isArray: true, description: 'The tokens')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function list(Request $request): Response
    {
        return Response::json(ApiTokenResource::of($this->tokens->listFor($this->authentication->userId(), currentWorkspaceId())));
    }

    #[Endpoint('List workspace API tokens', 'The API tokens of every member for the current workspace, oldest first : the agents drawn on the home circuit', 'listWorkspaceApiTokens')]
    #[ResourceResponse(ApiTokenResource::class, isArray: true, description: 'The tokens')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function listWorkspace(Request $request): Response
    {
        return Response::json(ApiTokenResource::of($this->tokens->listForWorkspace(currentWorkspaceId())));
    }

    #[Endpoint('Create API token', 'Creates an API token of the user for the current workspace. Its value is only given by this answer', 'createApiToken')]
    #[ObjectResponse(['token' => ApiTokenResource::class, 'value' => 'tower_abcdef'], responseCode: StatusCode::CREATED, description: 'The token and its value')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'MCP access is blocked for this workspace'], responseCode: StatusCode::CONFLICT, description: 'The workspace refuses MCP connections')]
    public function create(CreateApiTokenRequest $request): Response
    {
        $workspace = currentWorkspace();
        if (WorkspaceService::MCP_BLOCKED === $workspace->mcp_access)
            return Response::json(['error' => 'MCP access is blocked for this workspace'], StatusCode::CONFLICT);

        [$token, $value] = $this->tokens->create(
            $this->authentication->userId(),
            $workspace->id,
            $request->validated('name'),
            $request->validated('scope'),
            $request->validated('expires_in_days'),
        );
        $this->history->log($token->workspace, 'token.created', ['name' => $token->name, 'scope' => $token->scope], user: $token->user);

        return Response::json(['token' => ApiTokenResource::of($token), 'value' => $value], StatusCode::CREATED);
    }

    #[Endpoint('Revoke API token', 'Deletes an API token of the user for the current workspace : it stops working at once', 'revokeApiToken')]
    #[NoContentResponse('The token is revoked')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Token not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such token for this user in the current workspace')]
    public function revoke(Request $request, int $token): Response
    {
        $found = $this->tokens->find($this->authentication->userId(), currentWorkspaceId(), $token);
        if (!$found)
            return Response::json(['error' => 'Token not found'], StatusCode::NOT_FOUND);

        $this->tokens->revoke($found);
        $this->history->log($found->workspace, 'token.revoked', ['name' => $found->name], user: $found->user);

        return Response::noContent();
    }
}
