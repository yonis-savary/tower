<?php

namespace App\Domain\Integrations;

final class PullRequestComment
{
    /**
     * @param ?string $parent   The comment answered, null for the first comment of a thread
     * @param ?string $path     The file an inline comment is written on, null for a general comment
     * @param ?int    $line     Its line in the new version of the file, else in the old one
     * @param bool    $resolved Whether its thread is resolved
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $parent,
        public readonly string $author,
        public readonly string $created,
        public readonly string $body,
        public readonly ?string $path,
        public readonly ?int $line,
        public readonly bool $resolved,
    ) {}
}
