<?php

namespace App\Integrations;

use App\Models\Integration;
use App\Models\PullRequest;

/**
 * Reads pull requests from their forge on demand : the stored state has neither their description,
 * their comments nor their diff, and only holds the followed ones.
 */
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
     * @return ?PullRequestContent null when the forge does not know it anymore
     */
    public function content(PullRequest $pullRequest): ?PullRequestContent
    {
        return $this->provider(Integration::find($pullRequest->integration))->pullRequestContent($pullRequest->repository, $pullRequest->external_id);
    }

    /**
     * @param string $repository Its slug in the forge workspace of the integration
     *
     * @return ?PullRequestContent null without integration, or when the forge does not know it
     */
    public function repositoryContent(int $workspace, string $repository, string $number): ?PullRequestContent
    {
        return $this->inRepository($workspace, $repository, fn (PullRequestProvider $provider, string $fullName) => $provider->pullRequestContent($fullName, $number));
    }

    /**
     * @return ?list<PullRequestData> null without integration, or when the forge does not know the repository
     */
    public function search(int $workspace, string $repository, ?string $branch, string $state, int $limit): ?array
    {
        return $this->inRepository($workspace, $repository, fn (PullRequestProvider $provider, string $fullName) => $provider->searchPullRequests($fullName, $branch, $state, $limit));
    }

    /**
     * @param ?string $path Only the files at this path, or under this directory
     *
     * @return ?string null without integration, or when the forge does not know it
     */
    public function diff(int $workspace, string $repository, string $number, ?string $path = null): ?string
    {
        $diff = $this->inRepository($workspace, $repository, fn (PullRequestProvider $provider, string $fullName) => $provider->pullRequestDiff($fullName, $number));

        return null === $diff || null === $path ? $diff : $this->onlyPath($diff, $path);
    }

    /**
     * @param bool $unresolved Only the comments of the threads still open
     *
     * @return array<string,mixed>
     */
    public function contentPayload(int $workspace, PullRequestContent $content, bool $unresolved = false): array
    {
        $comments = $unresolved
            ? array_values(array_filter($content->comments, fn (PullRequestComment $comment) => !$comment->resolved))
            : $content->comments;

        return [
            ...$this->summaryPayload($content->pullRequest, $this->storedIds($workspace, $content->pullRequest->repository)),
            'description' => $content->description,
            'files' => null === $content->files ? null : array_map(fn (PullRequestFile $file) => [
                'path' => $file->path,
                'previous_path' => $file->previousPath,
                'status' => $file->status,
                'lines_added' => $file->linesAdded,
                'lines_removed' => $file->linesRemoved,
            ], $content->files),
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

    /**
     * @param list<PullRequestData> $pullRequests Of one repository
     *
     * @return list<array<string,mixed>>
     */
    public function searchPayload(int $workspace, array $pullRequests): array
    {
        if ([] === $pullRequests)
            return [];

        $stored = $this->storedIds($workspace, $pullRequests[0]->repository);

        return array_map(fn (PullRequestData $pullRequest) => [
            ...$this->summaryPayload($pullRequest, $stored),
            'comment_count' => $pullRequest->commentCount,
            'reviewers' => array_values(array_map(fn (array $reviewer) => ['name' => $reviewer['name'], 'status' => $reviewer['status']], $pullRequest->reviewers)),
        ], $pullRequests);
    }

    /**
     * @param array<string,int> $stored Stored ids by external id
     *
     * @return array<string,mixed>
     */
    private function summaryPayload(PullRequestData $pullRequest, array $stored): array
    {
        return [
            // null when Tower does not follow it
            'id' => $stored[$pullRequest->externalId] ?? null,
            'number' => $pullRequest->externalId,
            'repository' => $pullRequest->repository,
            'url' => $pullRequest->url,
            'title' => $pullRequest->title,
            'author' => $pullRequest->author,
            'state' => $pullRequest->state,
            'draft' => $pullRequest->draft,
            'source_branch' => $pullRequest->sourceBranch,
            'target_branch' => $pullRequest->targetBranch,
        ];
    }

    /**
     * @return array<string,int> By external id
     */
    private function storedIds(int $workspace, string $repository): array
    {
        $ids = [];
        $rows = PullRequest::select()
            ->where('workspace', $workspace)
            ->where('repository', $repository)
            ->fetch();
        foreach ($rows as $row)
            $ids[$row->external_id] = $row->id;

        return $ids;
    }

    /**
     * @template T
     *
     * @param \Closure(PullRequestProvider, string): T $read Receives the full name of the repository
     *
     * @return ?T
     */
    private function inRepository(int $workspace, string $repository, \Closure $read): mixed
    {
        $integration = $this->integrations->findBitbucket($workspace);
        if (!$integration)
            return null;

        // Always inside the forge workspace of the integration
        $fullName = $this->integrations->settingsOf($integration)['workspace'].'/'.$repository;

        return $read($this->provider($integration), $fullName);
    }

    // Keeps the sections of a unified diff whose file, before or after, is at this path or under it
    private function onlyPath(string $diff, string $path): string
    {
        $path = trim($path, '/');
        $sections = preg_split('/^(?=diff --git )/m', $diff);

        return implode('', array_filter($sections, function (string $section) use ($path) {
            if (!preg_match('/^diff --git a\/(.+?) b\/(.+?)$/m', $section, $files))
                return false;

            foreach ([$files[1], $files[2]] as $file) {
                if ($file === $path || str_starts_with($file, "{$path}/"))
                    return true;
            }

            return false;
        }));
    }

    protected function provider(Integration $integration): PullRequestProvider
    {
        return $this->integrations->providerFor($integration);
    }
}
