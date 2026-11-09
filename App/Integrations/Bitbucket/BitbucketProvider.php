<?php

namespace App\Integrations\Bitbucket;

use App\Integrations\PullRequestData;
use App\Integrations\PullRequestProvider;

class BitbucketProvider implements PullRequestProvider
{
    private const PAGE_LENGTH = 50;
    // Lists leave the participants out unless asked
    private const LIST_FIELDS = '+values.participants';

    private const STATES = [
        'OPEN' => PullRequestData::STATE_OPEN,
        'MERGED' => PullRequestData::STATE_MERGED,
        'DECLINED' => PullRequestData::STATE_DECLINED,
        'SUPERSEDED' => PullRequestData::STATE_SUPERSEDED,
    ];

    public function __construct(
        private readonly BitbucketClient $client,
        private readonly string $workspace,
    ) {}

    public function openPullRequests(): array
    {
        $user = $this->client->read('/user')['uuid'] ?? throw new \RuntimeException('Bitbucket does not know the account of this token');

        // The uuid comes with curly braces
        $values = $this->client->readAll("/workspaces/{$this->workspace}/pullrequests/".rawurlencode($user), [
            'state' => 'OPEN',
            'pagelen' => self::PAGE_LENGTH,
            'fields' => self::LIST_FIELDS,
        ]);

        return array_map(fn (array $pullRequest) => $this->toData($pullRequest), $values);
    }

    public function pullRequest(string $repository, string $externalId): ?PullRequestData
    {
        $pullRequest = $this->client->read("/repositories/{$repository}/pullrequests/{$externalId}");

        return $pullRequest ? $this->toData($pullRequest) : null;
    }

    public function pipelineStatus(string $repository, string $externalId): string
    {
        $statuses = array_column(
            $this->client->readAll("/repositories/{$repository}/pullrequests/{$externalId}/statuses", ['pagelen' => 100]),
            'state'
        );

        return match (true) {
            [] === $statuses => PullRequestData::PIPELINE_NONE,
            in_array('FAILED', $statuses, true), in_array('STOPPED', $statuses, true) => PullRequestData::PIPELINE_FAILED,
            in_array('INPROGRESS', $statuses, true) => PullRequestData::PIPELINE_RUNNING,
            default => PullRequestData::PIPELINE_SUCCESS,
        };
    }

    private function toData(array $pullRequest): PullRequestData
    {
        return new PullRequestData(
            externalId: (string) $pullRequest['id'],
            repository: (string) $pullRequest['destination']['repository']['full_name'],
            title: (string) $pullRequest['title'],
            author: (string) ($pullRequest['author']['display_name'] ?? ''),
            state: self::STATES[$pullRequest['state']] ?? strtolower((string) $pullRequest['state']),
            draft: (bool) ($pullRequest['draft'] ?? false),
            sourceBranch: (string) $pullRequest['source']['branch']['name'],
            targetBranch: (string) $pullRequest['destination']['branch']['name'],
            sourceCommit: (string) ($pullRequest['source']['commit']['hash'] ?? ''),
            url: (string) ($pullRequest['links']['html']['href'] ?? ''),
            commentCount: (int) ($pullRequest['comment_count'] ?? 0),
            reviewers: $this->reviewers($pullRequest['participants'] ?? []),
        );
    }

    /**
     * @return array<string,array{name: string, status: string}>
     */
    private function reviewers(array $participants): array
    {
        $reviewers = [];

        foreach ($participants as $participant) {
            $status = match (true) {
                'changes_requested' === ($participant['state'] ?? null) => PullRequestData::REVIEW_CHANGES_REQUESTED,
                true === ($participant['approved'] ?? false) => PullRequestData::REVIEW_APPROVED,
                default => PullRequestData::REVIEW_PENDING,
            };

            if ('REVIEWER' !== ($participant['role'] ?? null) && PullRequestData::REVIEW_PENDING === $status)
                continue;

            $user = $participant['user'] ?? [];
            $id = (string) ($user['account_id'] ?? $user['uuid'] ?? '');
            if ('' !== $id)
                $reviewers[$id] = ['name' => (string) ($user['display_name'] ?? ''), 'status' => $status];
        }

        return $reviewers;
    }
}
