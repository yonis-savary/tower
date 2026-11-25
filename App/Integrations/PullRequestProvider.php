<?php

namespace App\Integrations;

/**
 * What a forge driver must answer. Failures are thrown : a sync must not change anything then.
 */
interface PullRequestProvider
{
    /**
     * @return list<PullRequestData> The open pull requests of the account behind this integration
     */
    public function openPullRequests(): array;

    /**
     * @return ?PullRequestData null when it does not exist anymore
     */
    public function pullRequest(string $repository, string $externalId): ?PullRequestData;

    /**
     * @return ?PullRequestContent null when it does not exist anymore
     */
    public function pullRequestContent(string $repository, string $externalId): ?PullRequestContent;

    /**
     * @return string One of PullRequestData::PIPELINE_*
     */
    public function pipelineStatus(string $repository, string $externalId): string;
}
