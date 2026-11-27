<?php

namespace App\Controllers;

use App\Domain\Agenda\AgendaItemData;
use App\Domain\Agenda\AgendaService;
use App\Domain\Agenda\ScheduleOccurrence;
use App\Domain\Agenda\ScheduleOccurrences;
use App\Domain\Workspace\WorkspaceService;
use App\Mcp\McpTool;
use App\Middlewares\IsAuthenticated;
use App\Requests\AgendaRangeRequest;
use App\Requests\CreateAgendaItemRequest;
use App\Requests\DeleteAgendaItemRequest;
use App\Requests\ListAgendaItemsRequest;
use App\Requests\UpdateAgendaItemRequest;
use App\Resources\AgendaItemResource;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\NoContentResponse;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Data\OpenAPI\Attributes\ResourceResponse;
use Cube\Security\Authentication;
use Cube\Web\Controller;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

use function App\currentWorkspaceId;

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

    #[Endpoint('List agenda items', 'The agenda items overlapping [from, to) (ISO 8601 with their offset, or server time without one ; at most 62 days apart), earliest first, with their participants. A recurring item (`recurrence` set) is listed once per occurrence in the range, with the dates of that occurrence and its `occurrence` day, which names it to `update_agenda_item`. `workspaces` lists the ids to read, separated by commas : the current workspace when left out. `participants` (user ids, separated by commas) keeps the items one of them takes part in, plus those without participants. An agent only reads the workspace of its token', 'listAgendaItems')]
    #[ResourceResponse(AgendaItemResource::class, isArray: true, description: 'The agenda items', with: ['participants._user'])]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current user is not a member of a listed workspace')]
    #[McpTool('list_agenda_items')]
    public function list(ListAgendaItemsRequest $request): Response
    {
        $workspaces = $this->requestedWorkspaces($request);
        if (null === $workspaces)
            return $this->workspaceNotFound();

        return Response::json(AgendaItemResource::of($this->agenda->listIn($workspaces, $request->from(), $request->to(), $request->participants())));
    }

    #[Endpoint('Create agenda item', 'Adds an item to the agenda of `workspace` (the current workspace when left out). Dates are ISO 8601 with their offset, or server time without one ; `ends_at` is exclusive : an all-day item of one day ends at midnight the next day. Participants are members of that workspace. `color` (#rrggbb) replaces the color of the workspace, null keeps it. `recurrence` repeats the item from its dates : `frequency` daily, weekly, monthly or yearly, every `interval` periods (1 by default), on ISO `weekdays` for a weekly item (1 = Monday, the day it starts by default), until the day `until` included (forever when null). A monthly or yearly item skips the months without its day', 'createAgendaItem')]
    #[ResourceResponse(AgendaItemResource::class, responseCode: StatusCode::CREATED, description: 'The agenda item', with: ['participants._user'])]
    #[RawResponse(['error' => 'Workspace not found'], responseCode: StatusCode::NOT_FOUND, description: 'The current user is not a member of this workspace')]
    #[RawResponse(['error' => 'Every participant must be a member of the workspace'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'A participant is not a member of the workspace, or an invalid field')]
    #[McpTool('create_agenda_item', write: true)]
    public function create(CreateAgendaItemRequest $request): Response
    {
        $validated = $request->validated();
        $workspace = $validated['workspace'] ?? currentWorkspaceId();
        if (!in_array($workspace, $this->reachable(), true))
            return $this->workspaceNotFound();

        $item = $this->agenda->create($workspace, $this->authentication->userId(), AgendaItemData::fromValidated($validated));
        if (!$item)
            return $this->participantsRefused();

        return Response::json(AgendaItemResource::of($item), StatusCode::CREATED);
    }

    #[Endpoint('Update agenda item', 'Replaces the title, description, dates, link, color, participants and recurrence of an agenda item. It stays in its workspace. Without `occurrence`, the dates are those of the first occurrence. For a recurring item, `occurrence` (the day a listed occurrence gives) makes the dates those of that occurrence, and `scope` tells what changes : `occurrence` (the default) moves it out of its item, as an item of its own happening once ; `following` ends the item the day before and starts a new one ; `series` moves every occurrence as this one moves. The answer is the item holding the change', 'updateAgendaItem')]
    #[ResourceResponse(AgendaItemResource::class, description: 'The agenda item', with: ['participants._user'])]
    #[RawResponse(['error' => 'Agenda item not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such item in a workspace of the current user, or no such occurrence of it')]
    #[RawResponse(['error' => 'Every participant must be a member of the workspace'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'A participant is not a member of the workspace, or an invalid field')]
    #[McpTool('update_agenda_item', write: true)]
    public function update(UpdateAgendaItemRequest $request, int $item): Response
    {
        $found = $this->agenda->find($this->reachable(), $item);
        if (!$found)
            return $this->itemNotFound();

        $occurrence = null;
        if (null !== $request->occurrence()) {
            $occurrence = $this->agenda->occurrenceOf($found, $request->occurrence());
            if (!$occurrence)
                return $this->itemNotFound();
        }

        $updated = $this->agenda->update($found, $this->authentication->userId(), AgendaItemData::fromValidated($request->validated()), $occurrence, $request->scope());
        if (!$updated)
            return $this->participantsRefused();

        return Response::json(AgendaItemResource::of($updated));
    }

    #[Endpoint('Delete agenda item', 'Deletes an agenda item and its participants, every occurrence of a recurring one. With `occurrence`, `scope` tells which occurrences go : `occurrence` (the default) that one only, `following` this one and the next ones, `series` all of them', 'deleteAgendaItem')]
    #[NoContentResponse('The agenda item is deleted')]
    #[RawResponse(['error' => 'Agenda item not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such item in a workspace of the current user, or no such occurrence of it')]
    public function delete(DeleteAgendaItemRequest $request, int $item): Response
    {
        $found = $this->agenda->find($this->reachable(), $item);
        if (!$found)
            return $this->itemNotFound();

        $occurrence = null;
        if (null !== $request->occurrence()) {
            $occurrence = $this->agenda->occurrenceOf($found, $request->occurrence());
            if (!$occurrence)
                return $this->itemNotFound();
        }

        $this->agenda->delete($found, $this->authentication->userId(), $occurrence, $request->scope());

        return Response::noContent();
    }

    #[Endpoint('List schedule occurrences', 'The runs of the enabled scheduled events in [from, to) (ISO 8601 with their offset, or server time without one ; at most 62 days apart), earliest first. A schedule running several times an hour gives one `frequent` occurrence a day, at its first run, with its number of `runs` that day', 'listAgendaSchedules')]
    #[RawResponse([['schedule' => 3, 'workspace' => 1, 'name' => 'Standup', 'type' => 'standup.remind', 'at' => '2026-11-24T09:00:00+01:00', 'frequent' => false, 'runs' => 1]], description: 'The occurrences')]
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
        $requested = $request->workspaces() ?? [currentWorkspaceId()];

        return array_diff($requested, $this->reachable()) ? null : $requested;
    }

    /**
     * @return list<int>
     */
    private function reachable(): array
    {
        return $this->workspaces->reachableIds($this->authentication->userId());
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
