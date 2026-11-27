<?php

namespace App\Mcp;

use App\Domain\Workspace\WorkspaceSignals;
use App\Models\ApiToken;
use App\Security\ApiTokenIdentity;
use App\Security\ApiTokenService;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Builder;
use Mcp\Server\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;

class McpServer
{
    public const NAME = 'Tower';
    public const VERSION = '1.0.0';

    private const INSTRUCTIONS = <<<'TEXT'
        Tower holds the tasks, sprints, Jira issues, merge requests, events and history of one workspace,
        the one the API token was created for. Tools whose description starts with [write] change data
        or send events to devices : confirm with the user before calling them.
        TEXT;

    public function __construct(
        protected McpToolCatalog $catalog,
        protected ApiTokenService $tokens,
        protected ApiTokenIdentity $identity,
        protected McpLogger $logger,
        protected WorkspaceSignals $signals,
    ) {}

    public function handle(Request $request, ApiToken $token): Response
    {
        $factory = new HttpFactory();

        // No CORS nor DNS rebinding middleware : only a bearer token authenticates, never a cookie a
        // page could make the browser send
        $transport = new StreamableHttpTransport($this->toPsrRequest($request), $factory, $factory, $this->logger, middleware: []);

        return $this->fromPsrResponse($this->buildFor($token)->run($transport));
    }

    private function buildFor(ApiToken $token): Server
    {
        $builder = Server::builder()
            ->setServerInfo(self::NAME, self::VERSION)
            ->setInstructions(self::INSTRUCTIONS)
            ->setLogger($this->logger);

        $this->catalog->tools()
            ->filter(fn (McpToolRoute $tool) => !$tool->write || $this->tokens->canWrite($token))
            ->forEach(fn (McpToolRoute $tool) => $this->register($builder, $tool, $token));

        return $builder->build();
    }

    private function register(Builder $builder, McpToolRoute $tool, ApiToken $token): void
    {
        $definition = new Tool(
            name: $tool->name,
            title: $tool->title,
            inputSchema: $tool->inputSchema,
            description: trim(($tool->write ? '[write] ' : '').$tool->description),
            annotations: new ToolAnnotations(title: $tool->title, readOnlyHint: !$tool->write, openWorldHint: false),
        );

        $builder->add($definition, new RouteToolHandler($tool, $token, $this->identity, $this->signals));
    }

    private function toPsrRequest(Request $request): ServerRequest
    {
        return new ServerRequest($request->getMethod(), $request->getPath(), $request->getHeaders(), $request->getBody());
    }

    private function fromPsrResponse(ResponseInterface $response): Response
    {
        $headers = array_map(fn (array $values) => implode(', ', $values), $response->getHeaders());

        return new Response($response->getStatusCode(), (string) $response->getBody(), $headers);
    }
}
