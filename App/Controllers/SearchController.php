<?php

namespace App\Controllers;

use App\Domain\Search\SearchResult;
use App\Domain\Search\WorkspaceSearch;
use App\Mcp\McpTool;
use App\Middlewares\IsAuthenticated;
use App\Requests\SearchRequest;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Security\Authentication;
use Cube\Web\Controller;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

use function App\currentWorkspace;

class SearchController extends Controller
{
    public function __construct(
        protected WorkspaceSearch $search,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/search', [self::class, 'search']),
        ]);
    }

    #[Endpoint('Search workspace', 'Finds `q` (2 to 100 characters) in the current workspace, by type and in this order : merge requests (title, repository, branch), Jira issues (key, summary), tasks (title, description), quick actions (name, event type, description), documentation pages (title, content). At most 5 results a type, the unfinished ones first ; `done` marks a closed merge request, a finished issue or task, an archived page. A section the workspace does not show is not searched. `url` is the external link of a merge request or an issue', 'searchWorkspace')]
    #[RawResponse([['type' => 'jira_issue', 'id' => 12, 'title' => 'Fix the login form', 'detail' => 'APP-42 · In progress', 'url' => 'https://example.atlassian.net/browse/APP-42', 'done' => false]], description: 'The results')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('search_workspace')]
    public function search(SearchRequest $request): Response
    {
        $workspace = currentWorkspace();
        $results = $this->search->search($workspace, trim($request->validated('q')));

        return Response::json(array_map(fn (SearchResult $result) => $result->toArray(), $results));
    }
}
