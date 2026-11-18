<?php

namespace App\Controllers;

use App\Mcp\McpServer;
use App\Security\ApiTokenService;
use Cube\Data\OpenAPI\Attributes\Hidden;
use Cube\Web\Controller;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

#[Hidden]
class McpController extends Controller
{
    public function __construct(
        protected ApiTokenService $tokens,
        protected McpServer $server,
    ) {}

    public function routes(Router $router): void
    {
        // Authenticated by an API token, not by a session
        $router->addRoutes(
            Route::any('/mcp', [self::class, 'handle']),
        );
    }

    public function handle(Request $request): Response
    {
        $authorization = $request->getHeader('authorization', '');
        $token = str_starts_with($authorization, 'Bearer ')
            ? $this->tokens->authenticate(trim(substr($authorization, 7)))
            : null;

        if (!$token)
            return Response::json(['error' => 'A valid Tower API token is required'], StatusCode::UNAUTHORIZED)
                ->withHeaders(['WWW-Authenticate' => 'Bearer realm="tower"']);

        if ($this->tokens->isBlocked($token))
            return Response::json(['error' => 'MCP access is blocked for this workspace'], StatusCode::FORBIDDEN);

        return $this->server->handle($request, $token);
    }
}
