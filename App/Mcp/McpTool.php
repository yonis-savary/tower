<?php

namespace App\Mcp;

#[\Attribute(\Attribute::TARGET_METHOD)]
class McpTool
{
    public function __construct(
        public readonly string $name,
        public readonly bool $write = false,
    ) {}
}
