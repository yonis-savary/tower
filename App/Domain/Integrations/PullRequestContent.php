<?php

namespace App\Domain\Integrations;

/**
 * A pull request read from its forge with what the stored state leaves out : its description and its comments.
 */
final class PullRequestContent
{
    /**
     * @param list<PullRequestComment> $comments Oldest first
     * @param ?list<PullRequestFile>   $files    The files it changes, null when the forge refuses to list them
     */
    public function __construct(
        public readonly PullRequestData $pullRequest,
        public readonly string $description,
        public readonly array $comments,
        public readonly ?array $files,
    ) {}
}
