<?php

namespace App\Integrations;

final class PullRequestFile
{
    public const STATUS_ADDED = 'added';
    public const STATUS_REMOVED = 'removed';
    public const STATUS_MODIFIED = 'modified';
    public const STATUS_RENAMED = 'renamed';

    /**
     * @param ?string $previousPath Its path before a rename, null otherwise
     * @param string  $status       One of the STATUS_* constants
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $previousPath,
        public readonly string $status,
        public readonly int $linesAdded,
        public readonly int $linesRemoved,
    ) {}
}
