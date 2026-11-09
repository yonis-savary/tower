<?php

namespace App\Controllers;

use App\Middlewares\IsAuthenticated;
use App\Models\PullRequest;
use App\Models\PullRequestApprovals;
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
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/pull-requests', [self::class, 'list']),
            Route::get('/pull-requests/approvals', [self::class, 'approvals']),
        ]);
    }

    #[Endpoint('List pull requests', 'The open and recently closed pull requests of the current workspace', 'listPullRequests')]
    #[ResourceResponse(PullRequestResource::class, isArray: true, description: 'The stored pull requests')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
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

    private function currentWorkspaceId(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
