<?php

namespace App\Domain\Integrations\Jira;

final class JiraAttachment
{
    public function __construct(
        public readonly string $id,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $created,
        public readonly ?string $author,
    ) {}
}
