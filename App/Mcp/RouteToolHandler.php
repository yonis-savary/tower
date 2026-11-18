<?php

namespace App\Mcp;

use App\Models\ApiToken;
use App\Security\ApiTokenIdentity;
use App\Services\WorkspaceSignals;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Server\ClientGateway;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\Handler\ToolHandlerInterface;

class RouteToolHandler implements ToolHandlerInterface
{
    private const TEXT_TYPES = ['application/xml', 'application/yaml', 'application/x-yaml', 'application/javascript', 'application/sql'];

    public function __construct(
        protected McpToolRoute $tool,
        protected ApiToken $token,
        protected ApiTokenIdentity $identity,
        protected WorkspaceSignals $signals,
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        $route = $this->tool->route;
        $request = $this->requestFor($arguments);
        $route->match($request);

        /** @var Response $response */
        $response = $this->identity->actAs($this->token, fn () => $route($request));

        $this->signals->send($this->token->workspace, WorkspaceSignals::MCP_CALLED, ['token' => $this->token->id, 'tool' => $this->tool->name, 'ok' => $response->isOk()]);

        $body = $response->getBody();
        if (!$response->isOk())
            throw new ToolCallException("HTTP {$response->getStatusCode()} : {$body}");

        if ('' === $body)
            return 'Done';

        return $response->isJSON() ? $response->getJSON() : $this->contentOf($response, $request->getPath());
    }

    private function contentOf(Response $response, string $path): Content
    {
        $mimeType = strtolower(trim(explode(';', $response->getHeader('content-type', 'application/octet-stream'))[0]));

        return match (true) {
            str_starts_with($mimeType, 'image/') => new ImageContent(base64_encode($response->getBody()), $mimeType),
            str_starts_with($mimeType, 'text/'), in_array($mimeType, self::TEXT_TYPES, true) => new TextContent($response->getBody()),
            default => EmbeddedResource::fromBlob('tower:'.$path, base64_encode($response->getBody()), $mimeType),
        };
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function requestFor(array $arguments): Request
    {
        $path = $this->tool->route->buildPath(array_map(fn (string $slug) => $arguments[$slug], $this->tool->slugs));
        $values = array_diff_key($arguments, array_flip($this->tool->slugs));
        $method = $this->tool->method();

        return 'GET' === $method
            ? new Request($method, $path, get: $values)
            : new Request($method, $path, headers: ['Content-Type' => 'application/json'], body: json_encode((object) $values, JSON_THROW_ON_ERROR));
    }
}
