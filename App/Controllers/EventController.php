<?php

namespace App\Controllers;

use App\Domain\Event\EventService;
use App\Mcp\McpTool;
use App\Middlewares\IsAuthenticated;
use App\Requests\CreateQuickActionRequest;
use App\Requests\TriggerEventRequest;
use App\Resources\QuickActionResource;
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

use function App\currentWorkspaceId;

class EventController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected EventService $events,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::post('/events', [self::class, 'trigger']),
            Route::get('/events/suggestions', [self::class, 'suggestions']),
            Route::get('/quick-actions', [self::class, 'listQuickActions']),
            Route::post('/quick-actions', [self::class, 'createQuickAction']),
            Route::delete('/quick-actions/{int:action}', [self::class, 'deleteQuickAction']),
            Route::post('/quick-actions/{int:action}/trigger', [self::class, 'triggerQuickAction']),
        ]);
    }

    #[Endpoint('Trigger event', 'Journals an event and sends it to the connected devices of the current workspace', 'triggerEvent')]
    #[NoContentResponse('The event is launched')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('trigger_event', write: true)]
    public function trigger(TriggerEventRequest $request): Response
    {
        $validated = $request->validated();
        $this->events->triggerAsUser(currentWorkspaceId(), $this->authentication->userId(), $validated['type'], $validated['payload']);

        return Response::noContent();
    }

    #[Endpoint('Suggest event types', 'The event types already used in the current workspace, by source : quick actions, webhooks, schedules and recently triggered events', 'suggestEventTypes')]
    #[RawResponse([['source' => 'quick_action', 'types' => ['deploy.start', 'notify.phone']], ['source' => 'history', 'types' => ['deploy.start']]], description: 'One entry per source that has types, each type once per source, sorted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('suggest_event_types')]
    public function suggestions(Request $request): Response
    {
        return Response::json($this->events->suggestedTypes(currentWorkspaceId()));
    }

    #[Endpoint('List quick actions', 'The predefined events of the current workspace', 'listQuickActions')]
    #[ResourceResponse(QuickActionResource::class, isArray: true, description: 'The quick actions')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('list_quick_actions')]
    public function listQuickActions(Request $request): Response
    {
        return Response::json(QuickActionResource::of($this->events->listQuickActions(currentWorkspaceId())));
    }

    #[Endpoint('Create quick action', 'Saves a predefined event in the current workspace', 'createQuickAction')]
    #[ResourceResponse(QuickActionResource::class, responseCode: StatusCode::CREATED, description: 'The quick action')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function createQuickAction(CreateQuickActionRequest $request): Response
    {
        $validated = $request->validated();
        $action = $this->events->createQuickAction(
            currentWorkspaceId(),
            $validated['name'],
            $validated['description'] ?? null,
            $validated['type'],
            $validated['payload'],
        );

        return Response::json(QuickActionResource::of($action), StatusCode::CREATED);
    }

    #[Endpoint('Delete quick action', 'Deletes a quick action of the current workspace', 'deleteQuickAction')]
    #[NoContentResponse('The quick action is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Quick action not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such quick action in the current workspace')]
    public function deleteQuickAction(Request $request, int $action): Response
    {
        $quickAction = $this->events->findQuickAction(currentWorkspaceId(), $action);
        if (!$quickAction)
            return $this->quickActionNotFound();

        $this->events->deleteQuickAction($quickAction);

        return Response::noContent();
    }

    #[Endpoint('Trigger quick action', 'Launches the event predefined by a quick action of the current workspace', 'triggerQuickAction')]
    #[NoContentResponse('The event is launched')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Quick action not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such quick action in the current workspace')]
    #[McpTool('trigger_quick_action', write: true)]
    public function triggerQuickAction(Request $request, int $action): Response
    {
        $workspace = currentWorkspaceId();
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
}
