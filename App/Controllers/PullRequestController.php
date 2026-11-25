<?php

namespace App\Controllers;

use App\Integrations\PullRequestData;
use App\Integrations\PullRequestService;
use App\Mcp\McpTool;
use App\Middlewares\IsAuthenticated;
use App\Models\PullRequest;
use App\Models\PullRequestApprovals;
use App\Requests\PullRequestDiffRequest;
use App\Requests\ReadPullRequestRequest;
use App\Requests\SearchPullRequestsRequest;
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
    // A repository slug, without its workspace : never a path out of it
    private const REPOSITORY = '[A-Za-z0-9_-][A-Za-z0-9._-]*';
    // About 70 000 tokens : more would fill the context of an agent
    private const MAX_DIFF_BYTES = 256 * 1024;

    private const CONTENT_EXAMPLE = [
        'id' => 4, 'number' => '12', 'repository' => 'team/api', 'url' => 'https://bitbucket.org/team/api/pull-requests/12',
        'title' => 'Add login', 'author' => 'Alice', 'state' => 'open', 'draft' => false, 'source_branch' => 'login', 'target_branch' => 'main',
        'description' => '## Context',
        'files' => [['path' => 'src/Login.php', 'previous_path' => null, 'status' => 'modified', 'lines_added' => 12, 'lines_removed' => 3]],
        'comments' => [['id' => '301', 'parent' => null, 'author' => 'Bob', 'created' => '2026-11-10T10:00:00.000000+00:00', 'body' => 'Rename this', 'path' => 'src/Login.php', 'line' => 42, 'resolved' => false]],
    ];

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
            Route::get('/repositories/{'.self::REPOSITORY.':repository}/pull-requests', [self::class, 'search']),
            Route::get('/repositories/{'.self::REPOSITORY.':repository}/pull-requests/{int:number}', [self::class, 'readInRepository']),
            Route::get('/repositories/{'.self::REPOSITORY.':repository}/pull-requests/{int:number}/diff', [self::class, 'diff']),
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

    #[Endpoint('Read pull request', 'Reads a stored pull request (its `id` from listPullRequests) from its forge, as readRepositoryPullRequest does', 'readPullRequest')]
    #[RawResponse(self::CONTENT_EXAMPLE, description: 'The pull request')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Pull request not found'], responseCode: StatusCode::NOT_FOUND, description: 'Not stored in the current workspace, or the forge does not know it anymore')]
    #[McpTool('get_pull_request')]
    public function read(ReadPullRequestRequest $request, int $pullRequest): Response
    {
        $workspace = $this->currentWorkspaceId();
        $found = $this->pullRequests->find($workspace, $pullRequest);
        $content = $found ? $this->pullRequests->content($found) : null;

        return $content
            ? Response::json($this->pullRequests->contentPayload($workspace, $content, (bool) $request->validated('unresolved')))
            : $this->pullRequestNotFound();
    }

    #[Endpoint('Search repository pull requests', 'Lists the pull requests of a repository of the Bitbucket workspace, read from Bitbucket : anyone\'s, followed by Tower or not, the most recently updated first. `repository` is its slug, without the workspace (`api` for `team/api`). Find the pull request of a branch with `branch` (its source branch) ; `state` defaults to `open`, `limit` to '.SearchPullRequestsRequest::DEFAULT_LIMIT.'. `id` is the id of listPullRequests when Tower follows it, else null', 'searchRepositoryPullRequests')]
    #[RawResponse([['id' => null, 'number' => '12', 'repository' => 'team/api', 'url' => 'https://bitbucket.org/team/api/pull-requests/12', 'title' => 'Add login', 'author' => 'Bob', 'state' => 'open', 'draft' => false, 'source_branch' => 'login', 'target_branch' => 'main', 'comment_count' => 3, 'reviewers' => [['name' => 'Alice', 'status' => 'pending']]]], description: 'The pull requests')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Repository not found'], responseCode: StatusCode::NOT_FOUND, description: 'No Bitbucket integration, or no such repository the account can read')]
    #[McpTool('search_repository_pull_requests')]
    public function search(SearchPullRequestsRequest $request, string $repository): Response
    {
        $workspace = $this->currentWorkspaceId();
        $found = $this->pullRequests->search(
            $workspace,
            $repository,
            $request->validated('branch'),
            $request->validated('state') ?? PullRequestData::STATE_OPEN,
            $request->validated('limit') ?? SearchPullRequestsRequest::DEFAULT_LIMIT,
        );

        return null === $found
            ? Response::json(['error' => 'Repository not found'], StatusCode::NOT_FOUND)
            : Response::json($this->pullRequests->searchPayload($workspace, $found));
    }

    #[Endpoint('Read repository pull request', 'Reads any pull request of a repository of the Bitbucket workspace by its number, followed by Tower or not, to review it : its description in Markdown, the files it changes (`files`, with their added and removed lines), and its comments in Markdown, oldest first. A reply carries the id of the comment it answers in `parent`, an inline comment its file in `path` and its line in `line` (null for a general comment), and `resolved` tells whether its thread is resolved. Narrow to the threads still open with `unresolved`. `repository` is its slug, without the workspace (`api` for `team/api`)', 'readRepositoryPullRequest')]
    #[RawResponse(self::CONTENT_EXAMPLE, description: 'The pull request')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Pull request not found'], responseCode: StatusCode::NOT_FOUND, description: 'No Bitbucket integration, or no such pull request the account can read')]
    #[McpTool('get_repository_pull_request')]
    public function readInRepository(ReadPullRequestRequest $request, string $repository, int $number): Response
    {
        $workspace = $this->currentWorkspaceId();
        $content = $this->pullRequests->repositoryContent($workspace, $repository, (string) $number);

        return $content
            ? Response::json($this->pullRequests->contentPayload($workspace, $content, (bool) $request->validated('unresolved')))
            : $this->pullRequestNotFound();
    }

    #[Endpoint('Read pull request diff', 'The unified diff of a pull request of a repository of the Bitbucket workspace, as text, from the point where its source branch left the target one. `repository` is its slug, without the workspace (`api` for `team/api`). Over 256 KiB, narrow with `path` : a file, or a directory for every file under it (see `files` of readRepositoryPullRequest)', 'readPullRequestDiff')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Pull request not found'], responseCode: StatusCode::NOT_FOUND, description: 'No Bitbucket integration, no such pull request the account can read, or no change at `path`')]
    #[RawResponse(['error' => 'Diff too large'], responseCode: StatusCode::CONTENT_TOO_LARGE, description: 'Over 256 KiB : narrow with `path`')]
    #[McpTool('get_pull_request_diff')]
    public function diff(PullRequestDiffRequest $request, string $repository, int $number): Response
    {
        $path = $request->validated('path');
        $diff = $this->pullRequests->diff($this->currentWorkspaceId(), $repository, (string) $number, $path);
        if (null === $diff)
            return $this->pullRequestNotFound();
        if ('' === $diff)
            return Response::json(['error' => "No change at {$path}"], StatusCode::NOT_FOUND);

        if (strlen($diff) > self::MAX_DIFF_BYTES)
            return Response::json(['error' => 'Diff too large : '.strlen($diff).' bytes, '.self::MAX_DIFF_BYTES.' at most. Narrow with path, a file or a directory'], StatusCode::CONTENT_TOO_LARGE);

        return new Response(StatusCode::OK, $diff, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function pullRequestNotFound(): Response
    {
        return Response::json(['error' => 'Pull request not found'], StatusCode::NOT_FOUND);
    }

    private function currentWorkspaceId(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
