<?php

namespace App\Integrations;

/**
 * A pull request as every forge driver must describe it, with Tower's own statuses.
 */
final class PullRequestData
{
    public const STATE_OPEN = 'open';
    public const STATE_MERGED = 'merged';
    public const STATE_DECLINED = 'declined';
    public const STATE_SUPERSEDED = 'superseded';
    public const STATES = [self::STATE_OPEN, self::STATE_MERGED, self::STATE_DECLINED, self::STATE_SUPERSEDED];

    public const PIPELINE_NONE = 'none';
    public const PIPELINE_PENDING = 'pending';
    public const PIPELINE_RUNNING = 'running';
    public const PIPELINE_SUCCESS = 'success';
    public const PIPELINE_FAILED = 'failed';

    public const REVIEW_PENDING = 'pending';
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_CHANGES_REQUESTED = 'changes_requested';

    /**
     * @param array<string,array{name: string, status: string}> $reviewers By external user id
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $repository,
        public readonly string $title,
        public readonly string $author,
        public readonly string $state,
        public readonly bool $draft,
        public readonly string $sourceBranch,
        public readonly string $targetBranch,
        public readonly string $sourceCommit,
        public readonly string $url,
        public readonly int $commentCount,
        public readonly array $reviewers,
    ) {}
}
