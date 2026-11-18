<?php

namespace App\Controllers;

use App\Favicons\FaviconService;
use App\Mcp\McpTool;
use App\Middlewares\IsAuthenticated;
use App\Requests\CreateSprintRequest;
use App\Requests\CreateTaskRequest;
use App\Requests\MoveTaskRequest;
use App\Requests\SaveSprintLinkRequest;
use App\Requests\TaskContentRequest;
use App\Resources\SprintLinkResource;
use App\Resources\SprintResource;
use App\Resources\TaskHistoryResource;
use App\Resources\TaskResource;
use App\Services\TaskService;
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

class TaskController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected TaskService $tasks,
        protected FaviconService $favicons,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/tasks', [self::class, 'list']),
            Route::post('/tasks', [self::class, 'create']),
            Route::put('/tasks/{int:task}', [self::class, 'update']),
            Route::put('/tasks/{int:task}/move', [self::class, 'move']),
            Route::get('/tasks/{int:task}/history', [self::class, 'history']),
            Route::get('/sprints', [self::class, 'listSprints']),
            Route::post('/sprints', [self::class, 'createSprint']),
            Route::post('/sprints/{int:sprint}/open', [self::class, 'openSprint']),
            Route::post('/sprints/{int:sprint}/close', [self::class, 'closeSprint']),
            Route::post('/sprints/{int:sprint}/links', [self::class, 'addSprintLink']),
            Route::put('/sprints/links/{int:link}', [self::class, 'updateSprintLink']),
            Route::delete('/sprints/links/{int:link}', [self::class, 'removeSprintLink']),
            Route::get('/sprints/links/{int:link}/icon', [self::class, 'sprintLinkIcon']),
        ]);
    }

    #[Endpoint('List tasks', 'Every task of the current workspace, in their order', 'listTasks')]
    #[ResourceResponse(TaskResource::class, isArray: true, description: 'The tasks')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('list_tasks')]
    public function list(Request $request): Response
    {
        return Response::json(TaskResource::of($this->tasks->listTasks($this->workspace())));
    }

    #[Endpoint('Create task', 'Adds a task at the end of the backlog or of a sprint', 'createTask')]
    #[ResourceResponse(TaskResource::class, responseCode: StatusCode::CREATED, description: 'The task')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'This sprint cannot receive tasks'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'Unknown or closed sprint, or an invalid field')]
    #[McpTool('create_task', write: true)]
    public function create(CreateTaskRequest $request): Response
    {
        $task = $this->tasks->createTask(
            $this->workspace(),
            $this->authentication->userId(),
            $request->validated('title'),
            $request->validated('description') ?? '',
            $request->validated('sprint'),
        );

        return $task
            ? Response::json(TaskResource::of($task), StatusCode::CREATED)
            : $this->invalidMove();
    }

    #[Endpoint('Update task', 'Changes the title and the description of a task', 'updateTask')]
    #[ResourceResponse(TaskResource::class, description: 'The task')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Task not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such task in the current workspace')]
    #[McpTool('update_task', write: true)]
    public function update(TaskContentRequest $request, int $task): Response
    {
        $found = $this->tasks->findTask($this->workspace(), $task);
        if (!$found)
            return $this->taskNotFound();

        $this->tasks->updateTask($found, $this->authentication->userId(), $request->validated('title'), $request->validated('description') ?? '');

        return Response::json(TaskResource::of($found));
    }

    #[Endpoint('Move task', 'Changes the status and the sprint of a task, and optionally the order of its destination list', 'moveTask')]
    #[ResourceResponse(TaskResource::class, description: 'The task')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Task not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such task in the current workspace')]
    #[RawResponse(['error' => 'This sprint cannot receive tasks'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'Unknown or closed sprint, or an invalid order')]
    #[McpTool('move_task', write: true)]
    public function move(MoveTaskRequest $request, int $task): Response
    {
        $found = $this->tasks->findTask($this->workspace(), $task);
        if (!$found)
            return $this->taskNotFound();

        $moved = $this->tasks->moveTask($found, $this->authentication->userId(), $request->validated('sprint'), $request->validated('status'), $request->validated('ordered'));

        return $moved ? Response::json(TaskResource::of($moved)) : $this->invalidMove();
    }

    #[Endpoint('List task history', 'What happened to a task and who did it, newest first', 'listTaskHistory')]
    #[ResourceResponse(TaskHistoryResource::class, isArray: true, description: 'The history lines of this task')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Task not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such task in the current workspace')]
    #[McpTool('list_task_history')]
    public function history(Request $request, int $task): Response
    {
        $found = $this->tasks->findTask($this->workspace(), $task);

        return $found ? Response::json(TaskHistoryResource::of($this->tasks->historyOf($found))) : $this->taskNotFound();
    }

    #[Endpoint('List sprints', 'The sprints of the current workspace, oldest first, with their links', 'listSprints')]
    #[ResourceResponse(SprintResource::class, with: ['links'], isArray: true, description: 'The sprints')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('list_sprints')]
    public function listSprints(Request $request): Response
    {
        return Response::json(SprintResource::of($this->tasks->listSprints($this->workspace())));
    }

    #[Endpoint('Create sprint', 'Plans a new sprint in the current workspace', 'createSprint')]
    #[ResourceResponse(SprintResource::class, with: ['links'], responseCode: StatusCode::CREATED, description: 'The sprint')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function createSprint(CreateSprintRequest $request): Response
    {
        $sprint = $this->tasks->createSprint($this->workspace(), $this->authentication->userId(), $request->validated('name'));

        return Response::json(SprintResource::of($sprint), StatusCode::CREATED);
    }

    #[Endpoint('Open sprint', 'Makes a sprint active, alongside the other active sprints of the current workspace', 'openSprint')]
    #[ResourceResponse(SprintResource::class, with: ['links'], description: 'The sprint')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Sprint not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such sprint in the current workspace')]
    public function openSprint(Request $request, int $sprint): Response
    {
        $found = $this->tasks->findSprint($this->workspace(), $sprint);
        if (!$found)
            return $this->sprintNotFound();

        $this->tasks->openSprint($found, $this->authentication->userId());

        return Response::json(SprintResource::of($found));
    }

    #[Endpoint('Close sprint', 'Marks an active sprint as finished. Its tasks stay where they are', 'closeSprint')]
    #[ResourceResponse(SprintResource::class, with: ['links'], description: 'The sprint')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Sprint not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such sprint in the current workspace')]
    #[RawResponse(['error' => 'This sprint is not active'], responseCode: StatusCode::CONFLICT, description: 'Only an active sprint can be closed')]
    public function closeSprint(Request $request, int $sprint): Response
    {
        $found = $this->tasks->findSprint($this->workspace(), $sprint);
        if (!$found)
            return $this->sprintNotFound();

        return $this->tasks->closeSprint($found, $this->authentication->userId())
            ? Response::json(SprintResource::of($found))
            : Response::json(['error' => 'This sprint is not active'], StatusCode::CONFLICT);
    }

    #[Endpoint('Add sprint link', 'Adds a handy link to a sprint that is not closed : a page the team opens during the whole sprint (board, mockup, document). Without label, the link shows its address', 'addSprintLink')]
    #[ResourceResponse(SprintLinkResource::class, responseCode: StatusCode::CREATED, description: 'The link')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Sprint not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such sprint in the current workspace')]
    #[RawResponse(['error' => 'This sprint is closed'], responseCode: StatusCode::CONFLICT, description: 'The links of a closed sprint no longer change')]
    #[McpTool('add_sprint_link', write: true)]
    public function addSprintLink(SaveSprintLinkRequest $request, int $sprint): Response
    {
        $found = $this->tasks->findSprint($this->workspace(), $sprint);
        if (!$found)
            return $this->sprintNotFound();

        $link = $this->tasks->addLink($found, $this->authentication->userId(), $request->validated('url'), $request->validated('label') ?: null);

        return $link ? Response::json(SprintLinkResource::of($link), StatusCode::CREATED) : $this->sprintClosed();
    }

    #[Endpoint('Update sprint link', 'Changes the address and the label of a link of a sprint that is not closed', 'updateSprintLink')]
    #[ResourceResponse(SprintLinkResource::class, description: 'The link')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Link not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such link in the current workspace')]
    #[RawResponse(['error' => 'This sprint is closed'], responseCode: StatusCode::CONFLICT, description: 'The links of a closed sprint no longer change')]
    public function updateSprintLink(SaveSprintLinkRequest $request, int $link): Response
    {
        $found = $this->tasks->findLink($this->workspace(), $link);
        if (!$found)
            return $this->linkNotFound();

        return $this->tasks->updateLink($found, $this->authentication->userId(), $request->validated('url'), $request->validated('label') ?: null)
            ? Response::json(SprintLinkResource::of($found))
            : $this->sprintClosed();
    }

    #[Endpoint('Remove sprint link', 'Removes a link from a sprint that is not closed', 'removeSprintLink')]
    #[NoContentResponse('The link is removed')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Link not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such link in the current workspace')]
    #[RawResponse(['error' => 'This sprint is closed'], responseCode: StatusCode::CONFLICT, description: 'The links of a closed sprint no longer change')]
    public function removeSprintLink(Request $request, int $link): Response
    {
        $found = $this->tasks->findLink($this->workspace(), $link);
        if (!$found)
            return $this->linkNotFound();

        return $this->tasks->removeLink($found, $this->authentication->userId()) ? Response::noContent() : $this->sprintClosed();
    }

    #[Endpoint('Get sprint link icon', 'The favicon of the site a link points to, read once a month by Tower. A 404 when the site has none or cannot be reached', 'getSprintLinkIcon')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Icon not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such link, or no icon for its site')]
    public function sprintLinkIcon(Request $request, int $link): Response
    {
        $found = $this->tasks->findLink($this->workspace(), $link);
        $favicon = $found ? $this->favicons->of($found->url) : null;
        if (!$favicon)
            return Response::json(['error' => 'Icon not found'], StatusCode::NOT_FOUND);

        return new Response(StatusCode::OK, $favicon->body, [
            'Content-Type' => $favicon->type,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // An SVG icon opened on its own must not run what it carries
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    private function invalidMove(): Response
    {
        return Response::json(['error' => 'This sprint cannot receive tasks'], StatusCode::UNPROCESSABLE_CONTENT);
    }

    private function taskNotFound(): Response
    {
        return Response::json(['error' => 'Task not found'], StatusCode::NOT_FOUND);
    }

    private function sprintNotFound(): Response
    {
        return Response::json(['error' => 'Sprint not found'], StatusCode::NOT_FOUND);
    }

    private function linkNotFound(): Response
    {
        return Response::json(['error' => 'Link not found'], StatusCode::NOT_FOUND);
    }

    private function sprintClosed(): Response
    {
        return Response::json(['error' => 'This sprint is closed'], StatusCode::CONFLICT);
    }

    private function workspace(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
