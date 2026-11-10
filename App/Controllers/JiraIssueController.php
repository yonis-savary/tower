<?php

namespace App\Controllers;

use App\Integrations\Jira\JiraIssueService;
use App\Integrations\Jira\JiraTransition;
use App\Middlewares\IsAuthenticated;
use App\Requests\TransitionJiraIssueRequest;
use App\Resources\JiraIssueResource;
use App\Resources\TaskResource;
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

class JiraIssueController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected JiraIssueService $issues,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/jira/board', [self::class, 'board']),
            Route::get('/jira/issues', [self::class, 'list']),
            Route::get('/jira/issues/{int:issue}/transitions', [self::class, 'transitions']),
            Route::post('/jira/issues/{int:issue}/transitions', [self::class, 'transition']),
            Route::post('/jira/issues/{int:issue}/task', [self::class, 'createTask']),
            Route::post('/jira/issues/{int:issue}/discuss', [self::class, 'discuss']),
        ]);
    }

    #[Endpoint('List Jira issues', 'The unfinished issues of the board (`tracked`), whoever they are assigned to, and the issues kept because a task is linked to them', 'listJiraIssues')]
    #[ResourceResponse(JiraIssueResource::class, isArray: true, description: 'The stored issues')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function list(Request $request): Response
    {
        return Response::json(JiraIssueResource::of($this->issues->listIssues($this->workspace())));
    }

    #[Endpoint('Get Jira board', 'The Jira account of the token, and the columns of the configured board in its order, with the ids of the statuses each one holds. Read on each sync', 'getJiraBoard')]
    #[RawResponse(['account_id' => '5b10a2844c20165700ede21g', 'columns' => [['name' => 'In Review', 'statuses' => ['10002', '10007']]]], description: 'The board, empty before the first sync')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function board(Request $request): Response
    {
        return Response::json($this->issues->board($this->workspace()));
    }

    #[Endpoint('List Jira issue transitions', 'The transitions the Jira workflow allows from the current status of the issue, read from Jira', 'listJiraIssueTransitions')]
    #[RawResponse([['id' => '21', 'name' => 'Start progress', 'status_id' => '10001', 'status' => 'In Progress', 'status_category' => 'in_progress']], description: 'The transitions')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Issue not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such issue in the current workspace')]
    public function transitions(Request $request, int $issue): Response
    {
        $found = $this->issues->findIssue($this->workspace(), $issue);
        if (!$found)
            return $this->issueNotFound();

        return Response::json(array_map(fn (JiraTransition $transition) => [
            'id' => $transition->id,
            'name' => $transition->name,
            'status_id' => $transition->statusId,
            'status' => $transition->status,
            'status_category' => $transition->statusCategory,
        ], $this->issues->transitions($found)));
    }

    #[Endpoint('Transition Jira issue', 'Applies a transition in Jira, then stores the new status of the issue', 'transitionJiraIssue')]
    #[ResourceResponse(JiraIssueResource::class, description: 'The issue in its new status')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Issue not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such issue in the current workspace')]
    #[RawResponse(['error' => 'Field "resolution" is required.'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'Jira refused the transition, for example because it needs more fields')]
    public function transition(TransitionJiraIssueRequest $request, int $issue): Response
    {
        $found = $this->issues->findIssue($this->workspace(), $issue);
        if (!$found)
            return $this->issueNotFound();

        $refusal = $this->issues->transition($found, $this->authentication->userId(), $request->validated('transition'));

        return null === $refusal
            ? Response::json(JiraIssueResource::of($found))
            : Response::json(['error' => $refusal], StatusCode::UNPROCESSABLE_CONTENT);
    }

    #[Endpoint('Create task from Jira issue', 'Adds a task linked to the issue at the end of the backlog', 'createTaskFromJiraIssue')]
    #[ResourceResponse(TaskResource::class, responseCode: StatusCode::CREATED, description: 'The task')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Issue not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such issue in the current workspace')]
    #[RawResponse(['error' => 'This issue already has a task'], responseCode: StatusCode::CONFLICT, description: 'An issue has one task at most')]
    public function createTask(Request $request, int $issue): Response
    {
        $found = $this->issues->findIssue($this->workspace(), $issue);
        if (!$found)
            return $this->issueNotFound();

        $task = $this->issues->createTask($found, $this->authentication->userId());

        return $task
            ? Response::json(TaskResource::of($task), StatusCode::CREATED)
            : Response::json(['error' => 'This issue already has a task'], StatusCode::CONFLICT);
    }

    #[Endpoint('Discuss Jira issue', 'Reads the issue from Jira (description, last comments...) and sends it to the connected devices as an `ai.discuss` event', 'discussJiraIssue')]
    #[NoContentResponse('The event is sent')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Issue not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such issue in the current workspace, or Jira does not know it anymore')]
    public function discuss(Request $request, int $issue): Response
    {
        $found = $this->issues->findIssue($this->workspace(), $issue);
        if (!$found || !$this->issues->discuss($found, $this->authentication->userId()))
            return $this->issueNotFound();

        return Response::noContent();
    }

    private function issueNotFound(): Response
    {
        return Response::json(['error' => 'Issue not found'], StatusCode::NOT_FOUND);
    }

    private function workspace(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
