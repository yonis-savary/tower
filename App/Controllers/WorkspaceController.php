<?php

namespace App\Controllers;

use App\Channels\WorkspaceDevicesChannel;
use App\Middlewares\IsAuthenticated;
use App\Models\Workspace;
use App\Requests\AddWorkspaceMemberRequest;
use App\Requests\SelectWorkspaceRequest;
use App\Requests\UpdateWorkspaceRequest;
use App\Requests\WorkspaceNameRequest;
use App\Resources\UserResource;
use App\Resources\WorkspaceResource;
use App\Security\WorkspaceSocketToken;
use App\Services\WorkspaceIconService;
use App\Services\WorkspaceService;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\NoContentResponse;
use Cube\Data\OpenAPI\Attributes\RawBody;
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
        protected WorkspaceIconService $icons,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api/workspaces', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/', [self::class, 'list']),
            Route::get('/current', [self::class, 'current']),
            Route::put('/current', [self::class, 'select']),
            Route::get('/current/socket', [self::class, 'socket']),
            Route::post('/', [self::class, 'create']),
            Route::put('/{int:workspace}', [self::class, 'update']),
            Route::delete('/{int:workspace}', [self::class, 'delete']),
            Route::get('/{int:workspace}/icon', [self::class, 'icon']),
            Route::put('/{int:workspace}/icon', [self::class, 'setIcon']),
            Route::delete('/{int:workspace}/icon', [self::class, 'removeIcon']),
            Route::get('/{int:workspace}/members', [self::class, 'members']),
            Route::post('/{int:workspace}/members', [self::class, 'addMember']),
            Route::delete('/{int:workspace}/members/{int:user}', [self::class, 'removeMember']),
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

    #[Endpoint('Create workspace', 'Creates a workspace whose only member is the current user. It is not selected', 'createWorkspace')]
    #[ResourceResponse(WorkspaceResource::class, responseCode: StatusCode::CREATED, description: 'The workspace')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function create(WorkspaceNameRequest $request): Response
    {
        $workspace = $this->workspaces->create($this->authentication->userId(), trim($request->validated('name')));

        return Response::json(WorkspaceResource::of($workspace), StatusCode::CREATED);
    }

    #[Endpoint('Update workspace', 'Changes the name, the accent color and, when given, the sections and the MCP access of a workspace the current user is a member of', 'updateWorkspace')]
    #[ResourceResponse(WorkspaceResource::class, description: 'The workspace')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The workspace does not exist or the user is not a member of it')]
    public function update(UpdateWorkspaceRequest $request, int $workspace): Response
    {
        $found = $this->workspaceOfUser($workspace);
        if (!$found)
            return $this->workspaceNotFound();

        $updated = $this->workspaces->update($found, trim($request->validated('name')), $request->validated('accent_color'), $request->validated('sections'), $request->validated('mcp_access'));

        return Response::json(WorkspaceResource::of($updated));
    }

    #[Endpoint('Delete workspace', 'Deletes a workspace and everything it holds : tasks, sprints, history, integrations, webhooks, access keys, API tokens, members. Only its author can, and not their last workspace', 'deleteWorkspace')]
    #[NoContentResponse('The workspace is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Only the author deletes a workspace'], responseCode: StatusCode::FORBIDDEN, description: 'The current user is a member but not the author')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The workspace does not exist or the user is not a member of it')]
    #[RawResponse(['error' => 'Keep at least one workspace'], responseCode: StatusCode::CONFLICT, description: 'This is the last workspace the current user is a member of')]
    public function delete(Request $request, int $workspace): Response
    {
        $found = $this->workspaceOfUser($workspace);
        if (!$found)
            return $this->workspaceNotFound();

        $userId = $this->authentication->userId();
        if ($found->author !== $userId)
            return Response::json(['error' => 'Only the author deletes a workspace'], StatusCode::FORBIDDEN);
        if ($this->workspaces->isLastWorkspaceOf($userId))
            return Response::json(['error' => 'Keep at least one workspace'], StatusCode::CONFLICT);

        $this->workspaces->delete($found);

        return Response::noContent();
    }

    #[Endpoint('Get workspace icon', 'The icon of a workspace : the image a member uploaded (PNG or JPEG), else the logo of Tower in the workspace color (SVG). The page uses it as its favicon', 'getWorkspaceIcon')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The workspace does not exist or the user is not a member of it')]
    public function icon(Request $request, int $workspace): Response
    {
        $found = $this->workspaceOfUser($workspace);
        if (!$found)
            return $this->workspaceNotFound();

        $icon = $this->icons->of($found);

        return new Response(StatusCode::OK, $icon->body, [
            'Content-Type' => $icon->type,
            // Its address changes with the icon (see WorkspaceResource) : a cached one never goes stale
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    #[Endpoint('Set workspace icon', 'Replaces the icon of a workspace the current user is a member of. The body is the image itself, PNG or JPEG, 1 MiB at most', 'setWorkspaceIcon')]
    #[RawBody('image/png', 'A PNG or JPEG image, 1 MiB at most')]
    #[ResourceResponse(WorkspaceResource::class, description: 'The workspace')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The workspace does not exist or the user is not a member of it')]
    #[RawResponse(['error' => 'The icon must be a PNG or JPEG image'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'Not a PNG or JPEG image, or larger than 1 MiB')]
    public function setIcon(Request $request, int $workspace): Response
    {
        $found = $this->workspaceOfUser($workspace);
        if (!$found)
            return $this->workspaceNotFound();

        $content = $request->getBody();
        if (strlen($content) > WorkspaceIconService::MAX_SIZE)
            return Response::json(['error' => 'The icon must not exceed 1 MiB'], StatusCode::UNPROCESSABLE_CONTENT);

        $type = $this->icons->typeOf($content);
        if (!$type)
            return Response::json(['error' => 'The icon must be a PNG or JPEG image'], StatusCode::UNPROCESSABLE_CONTENT);

        return Response::json(WorkspaceResource::of($this->icons->set($found, $content, $type)));
    }

    #[Endpoint('Remove workspace icon', 'Removes the uploaded icon of a workspace : it gets the logo of Tower in its color back', 'removeWorkspaceIcon')]
    #[ResourceResponse(WorkspaceResource::class, description: 'The workspace')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The workspace does not exist or the user is not a member of it')]
    public function removeIcon(Request $request, int $workspace): Response
    {
        $found = $this->workspaceOfUser($workspace);

        return $found ? Response::json(WorkspaceResource::of($this->icons->remove($found))) : $this->workspaceNotFound();
    }

    #[Endpoint('List workspace members', 'The members of a workspace the current user is a member of, oldest first', 'listWorkspaceMembers')]
    #[ResourceResponse(UserResource::class, isArray: true, description: 'The members')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The workspace does not exist or the user is not a member of it')]
    public function members(Request $request, int $workspace): Response
    {
        $found = $this->workspaceOfUser($workspace);

        return $found ? Response::json(UserResource::of($this->workspaces->members($found))) : $this->workspaceNotFound();
    }

    #[Endpoint('Add workspace member', 'Adds the user with this e-mail to the workspace. Adding a member again changes nothing', 'addWorkspaceMember')]
    #[ResourceResponse(UserResource::class, description: 'The member')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The workspace does not exist or the user is not a member of it')]
    #[RawResponse(['error' => 'No account with this e-mail'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'No active account has this e-mail, or an invalid field')]
    public function addMember(AddWorkspaceMemberRequest $request, int $workspace): Response
    {
        $found = $this->workspaceOfUser($workspace);
        if (!$found)
            return $this->workspaceNotFound();

        $member = $this->workspaces->addMember($found, $request->validated('email'));

        return $member
            ? Response::json(UserResource::of($member))
            : Response::json(['error' => 'No account with this e-mail'], StatusCode::UNPROCESSABLE_CONTENT);
    }

    #[Endpoint('Remove workspace member', 'Removes a member from a workspace, the current user included', 'removeWorkspaceMember')]
    #[NoContentResponse('The member is removed')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The workspace does not exist, or the current user or this user is not a member of it')]
    #[RawResponse(['error' => 'A workspace keeps at least one member'], responseCode: StatusCode::CONFLICT, description: 'This is the last member : a workspace is deleted, never left empty')]
    public function removeMember(Request $request, int $workspace, int $user): Response
    {
        $found = $this->workspaceOfUser($workspace);
        if (!$found || !$this->workspaces->isMember($user, $found->id))
            return $this->workspaceNotFound();

        return $this->workspaces->removeMember($found, $user)
            ? Response::noContent()
            : Response::json(['error' => 'A workspace keeps at least one member'], StatusCode::CONFLICT);
    }

    private function workspaceOfUser(int $workspace): ?Workspace
    {
        return $this->workspaces->findFor($this->authentication->userId(), $workspace);
    }

    private function workspaceNotFound(): Response
    {
        return Response::json(['error' => 'Workspace not found'], StatusCode::NOT_FOUND);
    }
}
