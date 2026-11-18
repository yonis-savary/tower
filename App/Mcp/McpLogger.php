<?php

namespace App\Mcp;

use Cube\Env\Logger\Logger;
use Psr\Log\AbstractLogger;

class McpLogger extends AbstractLogger
{
    public function __construct(
        protected Logger $logger,
    ) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        // The SDK puts exceptions in the context : Cube's logger would print_r their whole object graph
        $context = array_map(
            fn (mixed $value) => $value instanceof \Throwable ? $value::class.': '.$value->getMessage().' at '.$value->getFile().':'.$value->getLine() : $value,
            $context,
        );

        $this->logger->log($level, $message, $context);
    }
}
