<?php

namespace App\Controllers;

use App\Domain\Event\EventService;
use App\Domain\Event\ScheduledEventService;
use App\Domain\History\HistoryService;
use App\Mcp\McpTool;
use App\Middlewares\IsAuthenticated;
use App\Requests\ScheduledEventRequest;
use App\Requests\UpdateScheduledEventRequest;
use App\Resources\ScheduledEventResource;
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

class ScheduledEventController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected ScheduledEventService $schedules,
        protected EventService $events,
        protected HistoryService $history,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/scheduled-events', [self::class, 'list']),
            Route::post('/scheduled-events', [self::class, 'create']),
            Route::put('/scheduled-events/{int:schedule}', [self::class, 'update']),
            Route::delete('/scheduled-events/{int:schedule}', [self::class, 'delete']),
            Route::post('/scheduled-events/{int:schedule}/trigger', [self::class, 'trigger']),
        ]);
    }

    #[Endpoint('List scheduled events', 'The scheduled events of the current workspace, oldest first', 'listScheduledEvents')]
    #[ResourceResponse(ScheduledEventResource::class, isArray: true, description: 'The scheduled events')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('list_scheduled_events')]
    public function list(Request $request): Response
    {
        return Response::json(ScheduledEventResource::of($this->schedules->listFor(currentWorkspaceId())));
    }

    #[Endpoint('Create scheduled event', 'Schedules an enabled event in the current workspace : recurring with `cron`, or once at `run_at` (server time), to the minute', 'createScheduledEvent')]
    #[ResourceResponse(ScheduledEventResource::class, responseCode: StatusCode::CREATED, description: 'The scheduled event')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function create(ScheduledEventRequest $request): Response
    {
        $validated = $request->validated();
        $schedule = $this->schedules->create(
            currentWorkspaceId(),
            $validated['name'],
            $validated['type'],
            $validated['payload'],
            $validated['cron'] ?? null,
            $validated['run_at'] ?? null,
        );
        $this->history->log($schedule->workspace, 'schedule.created', ['name' => $schedule->name], user: $this->authentication->userId());

        return Response::json(ScheduledEventResource::of($schedule), StatusCode::CREATED);
    }

    #[Endpoint('Update scheduled event', 'Changes the event, the timing and the state of a scheduled event of the current workspace', 'updateScheduledEvent')]
    #[ResourceResponse(ScheduledEventResource::class, description: 'The scheduled event')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Scheduled event not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such scheduled event in the current workspace')]
    public function update(UpdateScheduledEventRequest $request, int $schedule): Response
    {
        $validated = $request->validated();
        $found = $this->schedules->find(currentWorkspaceId(), $schedule);
        if (!$found)
            return $this->notFound();

        $wasEnabled = (bool) $found->enabled;
        $enabled = (bool) $validated['enabled'];
        $this->schedules->update(
            $found,
            $validated['name'],
            $validated['type'],
            $validated['payload'],
            $validated['cron'] ?? null,
            $validated['run_at'] ?? null,
            $enabled,
        );

        if ($wasEnabled !== $enabled)
            $this->history->log($found->workspace, $enabled ? 'schedule.enabled' : 'schedule.disabled', ['name' => $found->name], user: $this->authentication->userId());

        return Response::json(ScheduledEventResource::of($found));
    }

    #[Endpoint('Delete scheduled event', 'Deletes a scheduled event of the current workspace. The events it triggered stay in the history', 'deleteScheduledEvent')]
    #[NoContentResponse('The scheduled event is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Scheduled event not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such scheduled event in the current workspace')]
    public function delete(Request $request, int $schedule): Response
    {
        $found = $this->schedules->find(currentWorkspaceId(), $schedule);
        if (!$found)
            return $this->notFound();

        $this->schedules->delete($found);
        $this->history->log($found->workspace, 'schedule.deleted', ['name' => $found->name], user: $this->authentication->userId());

        return Response::noContent();
    }

    #[Endpoint('Trigger scheduled event', 'Launches the event of a scheduled event of the current workspace now, without changing its schedule', 'triggerScheduledEvent')]
    #[NoContentResponse('The event is launched')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Scheduled event not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such scheduled event in the current workspace')]
    public function trigger(Request $request, int $schedule): Response
    {
        $workspace = currentWorkspaceId();
        $found = $this->schedules->find($workspace, $schedule);
        if (!$found)
            return $this->notFound();

        $this->events->triggerAsUser($workspace, $this->authentication->userId(), $found->type, $found->payload);

        return Response::noContent();
    }

    private function notFound(): Response
    {
        return Response::json(['error' => 'Scheduled event not found'], StatusCode::NOT_FOUND);
    }
}
