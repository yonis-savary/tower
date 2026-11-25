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
     * Any pull request of a repository the account can read, not only the followed ones.
     *
     * @param ?string $branch Its source branch
     * @param string  $state  One of PullRequestData::STATES
     *
     * @return ?list<PullRequestData> The most recently updated first, null when the repository is unknown
     */
    public function searchPullRequests(string $repository, ?string $branch, string $state, int $limit): ?array;

    /**
     * @return ?string Its unified diff, from the point where its source branch left the target one, null when it does not exist
     */
    public function pullRequestDiff(string $repository, string $externalId): ?string;

    /**
     * @return string One of PullRequestData::PIPELINE_*
     */
    public function pipelineStatus(string $repository, string $externalId): string;
}
