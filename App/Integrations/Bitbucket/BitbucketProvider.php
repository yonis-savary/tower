<?php

namespace App\Integrations\Bitbucket;

use App\Integrations\PullRequestComment;
use App\Integrations\PullRequestContent;
use App\Integrations\PullRequestData;
use App\Integrations\PullRequestProvider;

class BitbucketProvider implements PullRequestProvider
{
    private const PAGE_LENGTH = 50;
    // The largest page the comments endpoint gives
    private const COMMENTS_PAGE_LENGTH = 100;
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

    public function pullRequestContent(string $repository, string $externalId): ?PullRequestContent
    {
        $pullRequest = $this->client->read("/repositories/{$repository}/pullrequests/{$externalId}");
        if (!$pullRequest)
            return null;

        $comments = $this->client->readAll("/repositories/{$repository}/pullrequests/{$externalId}/comments", ['pagelen' => self::COMMENTS_PAGE_LENGTH]);

        return new PullRequestContent(
            pullRequest: $this->toData($pullRequest),
            description: (string) ($pullRequest['summary']['raw'] ?? $pullRequest['description'] ?? ''),
            comments: $this->comments($comments),
        );
    }

    /**
     * @return list<PullRequestComment> Oldest first, without the deleted ones and the drafts
     */
    private function comments(array $comments): array
    {
        $byId = array_column($comments, null, 'id');
        $result = [];

        foreach ($comments as $comment) {
            if (($comment['deleted'] ?? false) || ($comment['pending'] ?? false))
                continue;

            $inline = $comment['inline'] ?? null;
            $parent = $comment['parent']['id'] ?? null;

            $result[] = new PullRequestComment(
                id: (string) $comment['id'],
                parent: null === $parent ? null : (string) $parent,
                author: (string) ($comment['user']['display_name'] ?? ''),
                created: (string) ($comment['created_on'] ?? ''),
                body: (string) ($comment['content']['raw'] ?? ''),
                path: isset($inline['path']) ? (string) $inline['path'] : null,
                line: isset($inline['to']) ? (int) $inline['to'] : (isset($inline['from']) ? (int) $inline['from'] : null),
                resolved: isset($this->threadRoot($comment, $byId)['resolution']),
            );
        }

        usort($result, fn (PullRequestComment $a, PullRequestComment $b) => [$a->created, (int) $a->id] <=> [$b->created, (int) $b->id]);

        return $result;
    }

    // Bitbucket marks the resolution on the first comment of a thread only
    private function threadRoot(array $comment, array $byId): array
    {
        $seen = [];
        while (null !== ($parent = $comment['parent']['id'] ?? null) && isset($byId[$parent]) && !isset($seen[$parent])) {
            $seen[$parent] = true;
            $comment = $byId[$parent];
        }

        return $comment;
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
