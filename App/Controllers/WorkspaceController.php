<?php

namespace App\Controllers;

use App\Middlewares\IsAuthenticated;
use App\Requests\SelectWorkspaceRequest;
use App\Resources\WorkspaceResource;
use App\Services\WorkspaceService;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Data\OpenAPI\Attributes\ResourceResponse;
use Cube\Security\Authentication;
use Cube\Web\Controller;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

class WorkspaceController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api/workspaces', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/', [self::class, 'list']),
            Route::get('/current', [self::class, 'current']),
            Route::put('/current', [self::class, 'select']),
        ]);
    }

    #[Endpoint('List workspaces', 'The workspaces the current user is a member of', 'listWorkspaces')]
    #[ResourceResponse(WorkspaceResource::class, isArray: true, description: 'The workspaces of the user')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function list(Request $request): Response
    {
        return Response::json(WorkspaceResource::of($this->workspaces->listFor($this->authentication->userId())));
    }

    #[Endpoint('Current workspace', 'The workspace selected in the current session', 'getCurrentWorkspace')]
    #[ResourceResponse(WorkspaceResource::class, description: 'The selected workspace')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'No workspace'], responseCode: StatusCode::NOT_FOUND, description: 'The user belongs to no workspace')]
    public function current(Request $request): Response
    {
        $workspace = $this->workspaces->current($this->authentication->userId());
        if (!$workspace)
            return Response::json(['error' => 'No workspace'], StatusCode::NOT_FOUND);

        return Response::json(WorkspaceResource::of($workspace));
    }

    #[Endpoint('Select workspace', 'Changes the workspace of the current session', 'selectWorkspace')]
    #[ResourceResponse(WorkspaceResource::class, description: 'The newly selected workspace')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The workspace does not exist or the user is not a member of it')]
    public function select(SelectWorkspaceRequest $request): Response
    {
        $workspace = $this->workspaces->select($this->authentication->userId(), $request->validated('workspace'));
        if (!$workspace)
            return Response::json(['error' => 'Workspace not found'], StatusCode::NOT_FOUND);

        return Response::json(WorkspaceResource::of($workspace));
    }
}
