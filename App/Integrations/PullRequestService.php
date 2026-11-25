<?php

namespace App\Integrations;

use App\Models\Integration;
use App\Models\PullRequest;

class PullRequestService
{
    public function __construct(
        protected IntegrationService $integrations,
    ) {}

    public function find(int $workspace, int $id): ?PullRequest
    {
        return PullRequest::findWhere(['id' => $id, 'workspace' => $workspace]);
    }

    /**
     * Reads the pull request from its forge : the stored state has neither its description nor its comments.
     *
     * @return ?PullRequestContent null when the forge does not know it anymore
     */
    public function content(PullRequest $pullRequest): ?PullRequestContent
    {
        return $this->provider($pullRequest)->pullRequestContent($pullRequest->repository, $pullRequest->external_id);
    }

    /**
     * @param bool $unresolved Only the comments of the threads still open
     *
     * @return array<string,mixed>
     */
    public function contentPayload(PullRequest $pullRequest, PullRequestContent $content, bool $unresolved = false): array
    {
        $data = $content->pullRequest;
        $comments = $unresolved
            ? array_values(array_filter($content->comments, fn (PullRequestComment $comment) => !$comment->resolved))
            : $content->comments;

        return [
            'id' => $pullRequest->id,
            'number' => $data->externalId,
            'repository' => $data->repository,
            'url' => $data->url,
            'title' => $data->title,
            'author' => $data->author,
            'state' => $data->state,
            'draft' => $data->draft,
            'source_branch' => $data->sourceBranch,
            'target_branch' => $data->targetBranch,
            'description' => $content->description,
            'comments' => array_map(fn (PullRequestComment $comment) => [
                'id' => $comment->id,
                'parent' => $comment->parent,
                'author' => $comment->author,
                'created' => $comment->created,
                'body' => $comment->body,
                'path' => $comment->path,
                'line' => $comment->line,
                'resolved' => $comment->resolved,
            ], $comments),
        ];
    }

    protected function provider(PullRequest $pullRequest): PullRequestProvider
    {
        return $this->integrations->providerFor(Integration::find($pullRequest->integration));
    }
}
