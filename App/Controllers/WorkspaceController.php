<?php

namespace App\Controllers;

use App\Channels\WorkspaceDevicesChannel;
use App\Middlewares\IsAuthenticated;
use App\Requests\SelectWorkspaceRequest;
use App\Resources\WorkspaceResource;
use App\Security\WorkspaceSocketToken;
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
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class WorkspaceController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected WorkspaceSocketToken $socketTokens,
        protected WorkspaceDevicesChannel $devicesChannel,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api/workspaces', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/', [self::class, 'list']),
            Route::get('/current', [self::class, 'current']),
            Route::put('/current', [self::class, 'select']),
            Route::get('/current/socket', [self::class, 'socket']),
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

    #[Endpoint('Workspace socket', 'The websocket URL following the devices of the current workspace, valid for a minute', 'getWorkspaceSocket')]
    #[RawResponse(['url' => 'ws://localhost:8088/workspaces/1/devices?token=1794245494.abcdef'], description: 'The URL to open')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'No workspace'], responseCode: StatusCode::NOT_FOUND, description: 'The user belongs to no workspace')]
    public function socket(Request $request): Response
    {
        $workspace = $this->workspaces->current($this->authentication->userId());
        if (!$workspace)
            return Response::json(['error' => 'No workspace'], StatusCode::NOT_FOUND);

        $origin = BroadcastConfiguration::resolve()->getWebsocketOrigin();
        $query = http_build_query(['token' => $this->socketTokens->issue($workspace->id)]);

        return Response::json(['url' => $origin.$this->devicesChannel->path([$workspace->id]).'?'.$query]);
    }

    #[Endpoint('Select workspace','Changes the workspace of the current session', 'selectWorkspace')]
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
