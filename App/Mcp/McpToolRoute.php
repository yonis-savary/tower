<?php

namespace App\Mcp;

use Cube\Web\Router\Route;

class McpToolRoute
{
    /**
     * @param array<string,mixed> $inputSchema
     * @param list<string>        $slugs
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $write,
        public readonly string $title,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly array $slugs,
        public readonly Route $route,
    ) {}

    public function method(): string
    {
        return $this->route->getMethods()[0];
    }
}
