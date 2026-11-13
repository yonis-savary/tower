<?php

namespace App\Controllers;

use App\Middlewares\IsAuthenticated;
use App\Requests\CreateQuickActionRequest;
use App\Requests\TriggerEventRequest;
use App\Resources\QuickActionResource;
use App\Services\EventService;
use App\Services\WorkspaceService;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\NoContentResponse;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Data\OpenAPI\Attributes\ResourceResponse;
use Cube\Security\Authentication;
use Cube\Web\Controller;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

class EventController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected EventService $events,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::post('/events', [self::class, 'trigger']),
            Route::get('/quick-actions', [self::class, 'listQuickActions']),
            Route::post('/quick-actions', [self::class, 'createQuickAction']),
            Route::delete('/quick-actions/{int:action}', [self::class, 'deleteQuickAction']),
            Route::post('/quick-actions/{int:action}/trigger', [self::class, 'triggerQuickAction']),
        ]);
    }

    #[Endpoint('Trigger event', 'Journals an event and sends it to the connected devices of the current workspace', 'triggerEvent')]
    #[NoContentResponse('The event is launched')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function trigger(TriggerEventRequest $request): Response
    {
        $this->events->triggerAsUser($this->currentWorkspaceId(), $this->authentication->userId(), $request->validated('type'), $request->validated('payload'));

        return Response::noContent();
    }

    #[Endpoint('List quick actions', 'The predefined events of the current workspace', 'listQuickActions')]
    #[ResourceResponse(QuickActionResource::class, isArray: true, description: 'The quick actions')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function listQuickActions(Request $request): Response
    {
        return Response::json(QuickActionResource::of($this->events->listQuickActions($this->currentWorkspaceId())));
    }

    #[Endpoint('Create quick action', 'Saves a predefined event in the current workspace', 'createQuickAction')]
    #[ResourceResponse(QuickActionResource::class, responseCode: StatusCode::CREATED, description: 'The quick action')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function createQuickAction(CreateQuickActionRequest $request): Response
    {
        $action = $this->events->createQuickAction(
            $this->currentWorkspaceId(),
            $request->validated('name'),
            $request->validated('description'),
            $request->validated('type'),
            $request->validated('payload'),
        );

        return Response::json(QuickActionResource::of($action), StatusCode::CREATED);
    }

    #[Endpoint('Delete quick action', 'Deletes a quick action of the current workspace', 'deleteQuickAction')]
    #[NoContentResponse('The quick action is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Quick action not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such quick action in the current workspace')]
    public function deleteQuickAction(Request $request, int $action): Response
    {
        $quickAction = $this->events->findQuickAction($this->currentWorkspaceId(), $action);
        if (!$quickAction)
            return $this->quickActionNotFound();

        $this->events->deleteQuickAction($quickAction);

        return Response::noContent();
    }

    #[Endpoint('Trigger quick action', 'Launches the event predefined by a quick action of the current workspace', 'triggerQuickAction')]
    #[NoContentResponse('The event is launched')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Quick action not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such quick action in the current workspace')]
    public function triggerQuickAction(Request $request, int $action): Response
    {
        $workspace = $this->currentWorkspaceId();
        $quickAction = $this->events->findQuickAction($workspace, $action);
        if (!$quickAction)
            return $this->quickActionNotFound();

        $this->events->triggerAsUser($workspace, $this->authentication->userId(), $quickAction->type, $quickAction->payload);

        return Response::noContent();
    }

    private function quickActionNotFound(): Response
    {
        return Response::json(['error' => 'Quick action not found'], StatusCode::NOT_FOUND);
    }

    private function currentWorkspaceId(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
