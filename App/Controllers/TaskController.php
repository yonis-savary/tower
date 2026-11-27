<?php

namespace App\Controllers;

use App\Favicons\FaviconService;
use App\Mcp\McpTool;
use App\Middlewares\IsAuthenticated;
use App\Requests\CreateSprintRequest;
use App\Requests\CreateTaskNotesRequest;
use App\Requests\CreateTaskRequest;
use App\Requests\MoveTaskRequest;
use App\Requests\SaveSprintLinkRequest;
use App\Requests\TaskContentRequest;
use App\Requests\TaskNotesRequest;
use App\Requests\TimelineRequest;
use App\Resources\SprintLinkResource;
use App\Resources\SprintResource;
use App\Resources\TaskHistoryResource;
use App\Resources\TaskResource;
use App\Services\TaskService;
use App\Services\TaskTimelineService;
use App\Services\WorkspaceService;
use App\TaskNotes\TaskNotesParser;
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

class TaskController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected TaskService $tasks,
        protected FaviconService $favicons,
        protected TaskNotesParser $notesParser,
        protected TaskTimelineService $timeline,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/tasks', [self::class, 'list']),
            Route::get('/tasks/timeline', [self::class, 'timeline']),
            Route::post('/tasks', [self::class, 'create']),
            Route::post('/tasks/notes/preview', [self::class, 'previewNotes']),
            Route::post('/tasks/notes', [self::class, 'createFromNotes']),
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

    #[Endpoint('List tasks', 'Every task of the current workspace, in their order, with their link when they have one', 'listTasks')]
    #[ResourceResponse(TaskResource::class, isArray: true, description: 'The tasks')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('list_tasks')]
    public function list(Request $request): Response
    {
        return Response::json(TaskResource::of($this->tasks->listTasks(currentWorkspaceId())));
    }

    #[Endpoint('Task timeline', 'The tasks worked on between two days, both included (92 days at most) : in progress at some point of the period, or finished during it. Statuses fall in three categories : `todo`, `in_progress`, `done` (done and cancelled). Each task has `created_at`, `started_at` (when it first left todo, null if it never did), `ended_at` (when it was done or cancelled, null while it is open), `initial_category` (its category at the start of the period) and its status changes during the period in `changes`, with their categories. Status changes are kept 31 days : an older task is placed by its creation and its completion only. Oldest start first', 'getTaskTimeline')]
    #[RawResponse([['id' => 12, 'title' => 'Prepare the demo', 'status' => 'done', 'status_category' => 'done', 'sprint' => 3, 'sprint_name' => 'Sprint 3', 'jira_issue' => null, 'link' => null, 'created_at' => '2026-10-20T15:00:00+01:00', 'started_at' => '2026-11-02T09:12:00+01:00', 'ended_at' => '2026-11-05T17:40:00+01:00', 'initial_category' => 'todo', 'changes' => [['at' => '2026-11-02T09:12:00+01:00', 'from' => 'todo', 'to' => 'in_progress', 'from_category' => 'todo', 'to_category' => 'in_progress']]]], description: 'The tasks of the period')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('get_task_timeline')]
    public function timeline(TimelineRequest $request): Response
    {
        return Response::json($this->timeline->timeline($this->workspace(), $request->start(), $request->end()));
    }

    #[Endpoint('Create task', 'Adds a task at the end of the backlog or of a sprint, optionally with a single http(s) link to a page it is about', 'createTask')]
    #[ResourceResponse(TaskResource::class, responseCode: StatusCode::CREATED, description: 'The task')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'This sprint cannot receive tasks'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'Unknown or closed sprint, or an invalid field')]
    #[McpTool('create_task', write: true)]
    public function create(CreateTaskRequest $request): Response
    {
        $validated = $request->validated();
        $task = $this->tasks->createTask(
            currentWorkspaceId(),
            $this->authentication->userId(),
            $validated['title'],
            $validated['description'] ?? '',
            $validated['sprint'] ?? null,
            link: $validated['link'] ?? null,
        );

        return $task
            ? Response::json(TaskResource::of($task), StatusCode::CREATED)
            : $this->invalidMove();
    }

    #[Endpoint('Preview tasks from notes', 'Reads meeting notes and answers the tasks they describe and their errors by line, without creating anything. A line without indentation is a task title, the indented lines below it are its description (their common indentation removed), a line starting with // is ignored. At most 50 tasks', 'previewTaskNotes')]
    #[RawResponse(['tasks' => [['line' => 1, 'title' => 'Prepare the demo', 'description' => "- slides\n- dataset"]], 'errors' => [['line' => 4, 'error' => 'title_too_long']]], description: 'The tasks found, and the errors (title_too_long, description_too_long, description_without_task, too_many_tasks)')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('preview_task_notes')]
    public function previewNotes(TaskNotesRequest $request): Response
    {
        return Response::json($this->notesParser->parse($request->validated('text'))->toArray());
    }

    #[Endpoint('Create tasks from notes', 'Creates every task described by meeting notes (same syntax as previewTaskNotes) at the end of the backlog or of a sprint, all or none : notes with an error or without any task create nothing', 'createTaskNotes')]
    #[ResourceResponse(TaskResource::class, isArray: true, responseCode: StatusCode::CREATED, description: 'The tasks, in the order of the notes')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Invalid notes', 'tasks' => [], 'errors' => [['line' => 1, 'error' => 'description_without_task']]], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'Notes with an error or without any task, an unknown or closed sprint')]
    #[McpTool('create_tasks_from_notes', write: true)]
    public function createFromNotes(CreateTaskNotesRequest $request): Response
    {
        $validated = $request->validated();
        $notes = $this->notesParser->parse($validated['text']);
        if (!$notes->isCreatable())
            return Response::json(['error' => 'Invalid notes', ...$notes->toArray()], StatusCode::UNPROCESSABLE_CONTENT);

        $tasks = $this->tasks->createTasks(currentWorkspaceId(), $this->authentication->userId(), $notes->tasks, $validated['sprint'] ?? null);

        return null === $tasks
            ? $this->invalidMove()
            : Response::json(TaskResource::of($tasks), StatusCode::CREATED);
    }

    #[Endpoint('Update task', 'Changes the title, the description and the link of a task. A left out link is kept, a null link is removed', 'updateTask')]
    #[ResourceResponse(TaskResource::class, description: 'The task')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Task not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such task in the current workspace')]
    #[McpTool('update_task', write: true)]
    public function update(TaskContentRequest $request, int $task): Response
    {
        $validated = $request->validated();
        $found = $this->tasks->findTask(currentWorkspaceId(), $task);
        if (!$found)
            return $this->taskNotFound();

        $link = array_key_exists('link', $validated) ? $validated['link'] : $found->link;
        $this->tasks->updateTask($found, $this->authentication->userId(), $validated['title'], $validated['description'] ?? '', $link);

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
        $validated = $request->validated();
        $found = $this->tasks->findTask(currentWorkspaceId(), $task);
        if (!$found)
            return $this->taskNotFound();

        $moved = $this->tasks->moveTask($found, $this->authentication->userId(), $validated['sprint'], $validated['status'], $validated['ordered'] ?? null);

        return $moved ? Response::json(TaskResource::of($moved)) : $this->invalidMove();
    }

    #[Endpoint('List task history', 'What happened to a task and who did it, newest first', 'listTaskHistory')]
    #[ResourceResponse(TaskHistoryResource::class, isArray: true, description: 'The history lines of this task')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Task not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such task in the current workspace')]
    #[McpTool('list_task_history')]
    public function history(Request $request, int $task): Response
    {
        $found = $this->tasks->findTask(currentWorkspaceId(), $task);

        return $found ? Response::json(TaskHistoryResource::of($this->tasks->historyOf($found))) : $this->taskNotFound();
    }

    #[Endpoint('List sprints', 'The sprints of the current workspace, oldest first, with their links', 'listSprints')]
    #[ResourceResponse(SprintResource::class, with: ['links'], isArray: true, description: 'The sprints')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('list_sprints')]
    public function listSprints(Request $request): Response
    {
        return Response::json(SprintResource::of($this->tasks->listSprints(currentWorkspaceId())));
    }

    #[Endpoint('Create sprint', 'Plans a new sprint in the current workspace', 'createSprint')]
    #[ResourceResponse(SprintResource::class, with: ['links'], responseCode: StatusCode::CREATED, description: 'The sprint')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function createSprint(CreateSprintRequest $request): Response
    {
        $sprint = $this->tasks->createSprint(currentWorkspaceId(), $this->authentication->userId(), $request->validated('name'));

        return Response::json(SprintResource::of($sprint), StatusCode::CREATED);
    }

    #[Endpoint('Open sprint', 'Makes a sprint active, alongside the other active sprints of the current workspace', 'openSprint')]
    #[ResourceResponse(SprintResource::class, with: ['links'], description: 'The sprint')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Sprint not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such sprint in the current workspace')]
    public function openSprint(Request $request, int $sprint): Response
    {
        $found = $this->tasks->findSprint(currentWorkspaceId(), $sprint);
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
        $found = $this->tasks->findSprint(currentWorkspaceId(), $sprint);
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
        $validated = $request->validated();
        $found = $this->tasks->findSprint(currentWorkspaceId(), $sprint);
        if (!$found)
            return $this->sprintNotFound();

        $link = $this->tasks->addLink($found, $this->authentication->userId(), $validated['url'], ($validated['label'] ?? null) ?: null);

        return $link ? Response::json(SprintLinkResource::of($link), StatusCode::CREATED) : $this->sprintClosed();
    }

    #[Endpoint('Update sprint link', 'Changes the address and the label of a link of a sprint that is not closed', 'updateSprintLink')]
    #[ResourceResponse(SprintLinkResource::class, description: 'The link')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Link not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such link in the current workspace')]
    #[RawResponse(['error' => 'This sprint is closed'], responseCode: StatusCode::CONFLICT, description: 'The links of a closed sprint no longer change')]
    public function updateSprintLink(SaveSprintLinkRequest $request, int $link): Response
    {
        $validated = $request->validated();
        $found = $this->tasks->findLink(currentWorkspaceId(), $link);
        if (!$found)
            return $this->linkNotFound();

        return $this->tasks->updateLink($found, $this->authentication->userId(), $validated['url'], ($validated['label'] ?? null) ?: null)
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
        $found = $this->tasks->findLink(currentWorkspaceId(), $link);
        if (!$found)
            return $this->linkNotFound();

        return $this->tasks->removeLink($found, $this->authentication->userId()) ? Response::noContent() : $this->sprintClosed();
    }

    #[Endpoint('Get sprint link icon', 'The favicon of the site a link points to, read once a month by Tower. A 404 when the site has none or cannot be reached', 'getSprintLinkIcon')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Icon not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such link, or no icon for its site')]
    public function sprintLinkIcon(Request $request, int $link): Response
    {
        $found = $this->tasks->findLink(currentWorkspaceId(), $link);
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
}
