<?php

namespace App\Controllers;

use App\Integrations\PullRequestService;
use App\Mcp\McpTool;
use App\Middlewares\IsAuthenticated;
use App\Models\PullRequest;
use App\Models\PullRequestApprovals;
use App\Requests\ReadPullRequestRequest;
use App\Resources\PullRequestApprovalResource;
use App\Resources\PullRequestResource;
use App\Services\WorkspaceService;
use Cube\Data\Bunch;
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

class PullRequestController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected PullRequestService $pullRequests,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/pull-requests', [self::class, 'list']),
            Route::get('/pull-requests/approvals', [self::class, 'approvals']),
            Route::get('/pull-requests/{int:pullRequest}', [self::class, 'read']),
        ]);
    }

    #[Endpoint('List pull requests', 'The open and recently closed pull requests of the current workspace', 'listPullRequests')]
    #[ResourceResponse(PullRequestResource::class, isArray: true, description: 'The stored pull requests')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('list_pull_requests')]
    public function list(Request $request): Response
    {
        $rows = PullRequest::select()->where('workspace', $this->currentWorkspaceId())->order('id', 'DESC')->fetch();

        return Response::json(PullRequestResource::of(Bunch::wrap($rows)));
    }

    #[Endpoint('List pull request approvals', 'The reviewers of every stored pull request of the current workspace', 'listPullRequestApprovals')]
    #[ResourceResponse(PullRequestApprovalResource::class, isArray: true, description: 'The reviewers')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function approvals(Request $request): Response
    {
        $workspace = $this->currentWorkspaceId();
        $rows = PullRequestApprovals::select()->whereRaw("pull_request IN (SELECT id FROM pull_request WHERE workspace = {$workspace})")->fetch();

        return Response::json(PullRequestApprovalResource::of(Bunch::wrap($rows)));
    }

    #[Endpoint('Read pull request', 'Reads a stored pull request (its `id` from listPullRequests) from its forge : its description and its comments in Markdown, oldest first. A reply carries the id of the comment it answers in `parent`, an inline comment its file in `path` and its line in `line` (null for a general comment), and `resolved` tells whether its thread is resolved. Narrow to the threads still open with `unresolved`', 'readPullRequest')]
    #[RawResponse(['id' => 4, 'number' => '12', 'repository' => 'team/api', 'url' => 'https://bitbucket.org/team/api/pull-requests/12', 'title' => 'Add login', 'author' => 'Alice', 'state' => 'open', 'draft' => false, 'source_branch' => 'login', 'target_branch' => 'main', 'description' => '## Context', 'comments' => [['id' => '301', 'parent' => null, 'author' => 'Bob', 'created' => '2026-11-10T10:00:00.000000+00:00', 'body' => 'Rename this', 'path' => 'src/Login.php', 'line' => 42, 'resolved' => false]]], description: 'The pull request')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Pull request not found'], responseCode: StatusCode::NOT_FOUND, description: 'Not stored in the current workspace, or the forge does not know it anymore')]
    #[McpTool('get_pull_request')]
    public function read(ReadPullRequestRequest $request, int $pullRequest): Response
    {
        $found = $this->pullRequests->find($this->currentWorkspaceId(), $pullRequest);
        $content = $found ? $this->pullRequests->content($found) : null;
        if (!$content)
            return Response::json(['error' => 'Pull request not found'], StatusCode::NOT_FOUND);

        return Response::json($this->pullRequests->contentPayload($found, $content, (bool) $request->validated('unresolved')));
    }

    private function currentWorkspaceId(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
