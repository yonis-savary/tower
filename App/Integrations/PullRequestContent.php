<?php

namespace App\Integrations;

/**
 * A pull request read from its forge with what the stored state leaves out : its description and its comments.
 */
final class PullRequestContent
{
    /**
     * @param list<PullRequestComment> $comments Oldest first
     */
    public function __construct(
        public readonly PullRequestData $pullRequest,
        public readonly string $description,
        public readonly array $comments,
    ) {}
}
