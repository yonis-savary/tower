<?php

namespace App\Controllers;

use App\Middlewares\IsAuthenticated;
use App\Requests\CreateSprintRequest;
use App\Requests\CreateTaskRequest;
use App\Requests\MoveTaskRequest;
use App\Requests\TaskContentRequest;
use App\Resources\SprintResource;
use App\Resources\TaskHistoryResource;
use App\Resources\TaskResource;
use App\Services\TaskService;
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

class TaskController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected TaskService $tasks,
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
        ]);
    }

    #[Endpoint('List tasks', 'Every task of the current workspace, in their order', 'listTasks')]
    #[ResourceResponse(TaskResource::class, isArray: true, description: 'The tasks')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function list(Request $request): Response
    {
        return Response::json(TaskResource::of($this->tasks->listTasks($this->workspace())));
    }

    #[Endpoint('Create task', 'Adds a task at the end of the backlog or of a sprint', 'createTask')]
    #[ResourceResponse(TaskResource::class, responseCode: StatusCode::CREATED, description: 'The task')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'This sprint cannot receive tasks'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'Unknown or closed sprint, or an invalid field')]
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
    public function history(Request $request, int $task): Response
    {
        $found = $this->tasks->findTask($this->workspace(), $task);

        return $found ? Response::json(TaskHistoryResource::of($this->tasks->historyOf($found))) : $this->taskNotFound();
    }

    #[Endpoint('List sprints', 'The sprints of the current workspace, oldest first', 'listSprints')]
    #[ResourceResponse(SprintResource::class, isArray: true, description: 'The sprints')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function listSprints(Request $request): Response
    {
        return Response::json(SprintResource::of($this->tasks->listSprints($this->workspace())));
    }

    #[Endpoint('Create sprint', 'Plans a new sprint in the current workspace', 'createSprint')]
    #[ResourceResponse(SprintResource::class, responseCode: StatusCode::CREATED, description: 'The sprint')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function createSprint(CreateSprintRequest $request): Response
    {
        $sprint = $this->tasks->createSprint($this->workspace(), $this->authentication->userId(), $request->validated('name'));

        return Response::json(SprintResource::of($sprint), StatusCode::CREATED);
    }

    #[Endpoint('Open sprint', 'Makes a sprint active, alongside the other active sprints of the current workspace', 'openSprint')]
    #[ResourceResponse(SprintResource::class, description: 'The sprint')]
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
    #[ResourceResponse(SprintResource::class, description: 'The sprint')]
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

    private function workspace(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
