<?php

namespace App\Mcp;

use Cube\Data\Bunch;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Specs\Common\MakesSchemas;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

class McpToolCatalog
{
    use MakesSchemas;

    private const SLUG = '~\{(?:([^:}]+):)?([^}]+)\}~';

    public function __construct(
        protected Router $router,
    ) {}

    /**
     * @return Bunch<int,McpToolRoute>
     */
    public function tools(): Bunch
    {
        $this->router->loadRoutes();

        return Bunch::wrap($this->router->getRoutes())
            ->filter(fn (Route $route) => is_array($route->getCallback()))
            ->map(fn (Route $route) => $this->describe($route))
            ->filter(keepValues: true);
    }

    public function find(string $name): ?McpToolRoute
    {
        return $this->tools()->first(fn (McpToolRoute $tool) => $tool->name === $name);
    }

    private function describe(Route $route): ?McpToolRoute
    {
        $method = $route->getReflectionMethod();
        if (!$attribute = $method->getAttributes(McpTool::class)[0] ?? null)
            return null;

        /** @var McpTool $tool */
        $tool = $attribute->newInstance();
        /** @var ?Endpoint $endpoint */
        $endpoint = ($method->getAttributes(Endpoint::class)[0] ?? null)?->newInstance();

        preg_match_all(self::SLUG, $route->getPath(), $slugs, PREG_SET_ORDER);

        return new McpToolRoute(
            $tool->name,
            $tool->write,
            $endpoint?->summary ?? $tool->name,
            $endpoint?->description ?? '',
            $this->inputSchema($route, $slugs),
            array_map(fn (array $slug) => $slug[2], $slugs),
            $route,
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $slugs
     *
     * @return array<string,mixed>
     */
    private function inputSchema(Route $route, array $slugs): array
    {
        $schema = ['type' => 'object', 'properties' => []];
        if ($rules = $this->getRequestRules($route))
            $this->mutateParameterWithRule($rules, $schema);
        $schema['properties'] = (array) $schema['properties'];

        foreach ($slugs as [, $type, $name]) {
            $slugSchema = [];
            $this->mutateParameterWithSlugType($type ?: 'any', $slugSchema);
            $schema['properties'][$name] = $this->toEncodableSchema($slugSchema);
            $schema['required'][] = $name;
        }

        $schema['properties'] = $this->toEncodableSchema($schema['properties']);

        return $schema;
    }
}
