<?php

namespace App\Controllers;

use App\Agenda\AgendaItemData;
use App\Agenda\AgendaService;
use App\Agenda\ScheduleOccurrence;
use App\Agenda\ScheduleOccurrences;
use App\Mcp\McpTool;
use App\Middlewares\IsAuthenticated;
use App\Requests\AgendaItemRequest;
use App\Requests\AgendaRangeRequest;
use App\Requests\CreateAgendaItemRequest;
use App\Requests\ListAgendaItemsRequest;
use App\Resources\AgendaItemResource;
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

class AgendaController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected AgendaService $agenda,
        protected ScheduleOccurrences $occurrences,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api/agenda', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/items', [self::class, 'list']),
            Route::post('/items', [self::class, 'create']),
            Route::put('/items/{int:item}', [self::class, 'update']),
            Route::delete('/items/{int:item}', [self::class, 'delete']),
            Route::get('/schedules', [self::class, 'schedules']),
        ]);
    }

    #[Endpoint('List agenda items', 'The agenda items overlapping [from, to) (ISO 8601 with their offset, or server time without one ; at most 62 days apart), earliest first, with their participants. `workspaces` lists the ids to read, separated by commas : the current workspace when left out. `participants` (user ids, separated by commas) keeps the items one of them takes part in, plus those without participants. An agent only reads the workspace of its token', 'listAgendaItems')]
    #[ResourceResponse(AgendaItemResource::class, isArray: true, description: 'The agenda items', with: ['participants._user'])]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current user is not a member of a listed workspace')]
    #[McpTool('list_agenda_items')]
    public function list(ListAgendaItemsRequest $request): Response
    {
        $workspaces = $this->requestedWorkspaces($request);
        if (null === $workspaces)
            return $this->workspaceNotFound();

        return Response::json(AgendaItemResource::of($this->agenda->listIn($workspaces, $request->from(), $request->to(), $request->participants())));
    }

    #[Endpoint('Create agenda item', 'Adds an item to the agenda of `workspace` (the current workspace when left out). Dates are ISO 8601 with their offset, or server time without one ; `ends_at` is exclusive : an all-day item of one day ends at midnight the next day. Participants are members of that workspace. `color` (#rrggbb) replaces the color of the workspace, null keeps it', 'createAgendaItem')]
    #[ResourceResponse(AgendaItemResource::class, responseCode: StatusCode::CREATED, description: 'The agenda item', with: ['participants._user'])]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current user is not a member of this workspace')]
    #[RawResponse(['error' => 'Every participant must be a member of the workspace'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'A participant is not a member of the workspace, or an invalid field')]
    #[McpTool('create_agenda_item', write: true)]
    public function create(CreateAgendaItemRequest $request): Response
    {
        $workspace = $request->validated('workspace') ?? $this->currentWorkspaceId();
        if (!in_array($workspace, $this->reachable(), true))
            return $this->workspaceNotFound();

        $item = $this->agenda->create($workspace, $this->authentication->userId(), AgendaItemData::fromValidated($request->validated()));
        if (!$item)
            return $this->participantsRefused();

        return Response::json(AgendaItemResource::of($item), StatusCode::CREATED);
    }

    #[Endpoint('Update agenda item', 'Replaces the title, description, dates, link, color and participants of an agenda item. It stays in its workspace', 'updateAgendaItem')]
    #[ResourceResponse(AgendaItemResource::class, description: 'The agenda item', with: ['participants._user'])]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Agenda item not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such item in a workspace of the current user')]
    #[RawResponse(['error' => 'Every participant must be a member of the workspace'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'A participant is not a member of the workspace, or an invalid field')]
    #[McpTool('update_agenda_item', write: true)]
    public function update(AgendaItemRequest $request, int $item): Response
    {
        $found = $this->agenda->find($this->reachable(), $item);
        if (!$found)
            return $this->itemNotFound();

        $updated = $this->agenda->update($found, $this->authentication->userId(), AgendaItemData::fromValidated($request->validated()));
        if (!$updated)
            return $this->participantsRefused();

        return Response::json(AgendaItemResource::of($updated));
    }

    #[Endpoint('Delete agenda item', 'Deletes an agenda item and its participants', 'deleteAgendaItem')]
    #[NoContentResponse('The agenda item is deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Agenda item not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such item in a workspace of the current user')]
    public function delete(Request $request, int $item): Response
    {
        $found = $this->agenda->find($this->reachable(), $item);
        if (!$found)
            return $this->itemNotFound();

        $this->agenda->delete($found, $this->authentication->userId());

        return Response::noContent();
    }

    #[Endpoint('List schedule occurrences', 'The runs of the enabled scheduled events in [from, to) (ISO 8601 with their offset, or server time without one ; at most 62 days apart), earliest first. A schedule running several times an hour gives one `frequent` occurrence a day, at its first run, with its number of `runs` that day', 'listAgendaSchedules')]
    #[RawResponse([['schedule' => 3, 'workspace' => 1, 'name' => 'Standup', 'type' => 'standup.remind', 'at' => '2026-11-24T09:00:00+01:00', 'frequent' => false, 'runs' => 1]], description: 'The occurrences')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current user is not a member of a listed workspace')]
    public function schedules(AgendaRangeRequest $request): Response
    {
        $workspaces = $this->requestedWorkspaces($request);
        if (null === $workspaces)
            return $this->workspaceNotFound();

        $occurrences = $this->occurrences->between($workspaces, new \DateTimeImmutable($request->from()), new \DateTimeImmutable($request->to()));

        return Response::json(array_map(fn (ScheduleOccurrence $occurrence) => $occurrence->toArray(), $occurrences));
    }

    /**
     * @return ?list<int>
     */
    private function requestedWorkspaces(AgendaRangeRequest $request): ?array
    {
        $requested = $request->workspaces() ?? [$this->currentWorkspaceId()];

        return array_diff($requested, $this->reachable()) ? null : $requested;
    }

    /**
     * @return list<int>
     */
    private function reachable(): array
    {
        return $this->workspaces->reachableIds($this->authentication->userId());
    }

    private function currentWorkspaceId(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }

    private function workspaceNotFound(): Response
    {
        return Response::json(['error' => 'Workspace not found'], StatusCode::NOT_FOUND);
    }

    private function itemNotFound(): Response
    {
        return Response::json(['error' => 'Agenda item not found'], StatusCode::NOT_FOUND);
    }

    private function participantsRefused(): Response
    {
        return Response::json(['error' => 'Every participant must be a member of the workspace'], StatusCode::UNPROCESSABLE_CONTENT);
    }
}
