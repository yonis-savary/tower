<?php

namespace App\Controllers;

use App\Mcp\McpTool;
use App\Middlewares\IsAuthenticated;
use App\Requests\HistoryRequest;
use App\Resources\EventDeliveryResource;
use App\Resources\HistoryResource;
use App\Resources\RecentEventResource;
use App\Services\HistoryService;
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

class HistoryController extends Controller
{
    private const RECENT_LIMIT = 15;

    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected HistoryService $history,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/history', [self::class, 'list']),
            Route::get('/history/events', [self::class, 'recentEvents']),
            Route::get('/history/{int:line}/deliveries', [self::class, 'deliveries']),
        ]);
    }

    #[Endpoint('List history', 'The history of the current workspace, newest first, by pages of 50 lines', 'listHistory')]
    #[ResourceResponse(HistoryResource::class, isArray: true, description: 'The lines older than `before`, or the latest ones')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('list_history')]
    public function list(HistoryRequest $request): Response
    {
        return Response::json(HistoryResource::of($this->history->page($this->currentWorkspaceId(), $request->validated('before'))));
    }

    #[Endpoint('List recent events', 'The last events sent to the devices of the current workspace, newest first, with how their deliveries went', 'listRecentEvents')]
    #[ResourceResponse(RecentEventResource::class, isArray: true, description: 'The last 15 events')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    public function recentEvents(Request $request): Response
    {
        return Response::json(RecentEventResource::of($this->history->recentEvents($this->currentWorkspaceId(), self::RECENT_LIMIT)));
    }

    #[Endpoint('List event deliveries', 'The devices an event of the history was sent to, with their response', 'listEventDeliveries')]
    #[ResourceResponse(EventDeliveryResource::class, isArray: true, description: 'One delivery per contacted device')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'History line not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such line in the current workspace')]
    #[McpTool('list_event_deliveries')]
    public function deliveries(Request $request, int $line): Response
    {
        $history = $this->history->find($this->currentWorkspaceId(), $line);
        if (!$history)
            return Response::json(['error' => 'History line not found'], StatusCode::NOT_FOUND);

        return Response::json(EventDeliveryResource::of($this->history->deliveriesOf($history)));
    }

    private function currentWorkspaceId(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
