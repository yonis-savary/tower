<?php

namespace App\Integrations;

use App\Models\Integration;
use App\Models\PullRequest;
use App\Models\PullRequestApprovals;
use App\Services\HistoryService;
use Cube\Data\Database\Database;
use Cube\Env\Logger\Logger;

/**
 * Brings the stored pull requests in line with the forge, and writes the differences in the history.
 */
class PullRequestSync
{
    private const UNFINISHED_PIPELINES = [PullRequestData::PIPELINE_PENDING, PullRequestData::PIPELINE_RUNNING];

    public function __construct(
        protected IntegrationService $integrations,
        protected HistoryService $history,
    ) {}

    public function syncAll(): void
    {
        foreach (Integration::select()->fetch() as $integration)
            $this->sync($integration);
    }

    public function sync(Integration $integration): void
    {
        try {
            // Everything is read first : a failing forge must leave the stored state untouched
            $changes = $this->read($integration, $this->provider($integration));

            $failure = Database::getInstance()->transaction(fn () => $this->apply($integration, $changes));
            if ($failure)
                throw $failure;

            $integration->last_synced_at = date('Y-m-d H:i:s');
            $integration->last_error = null;
        } catch (\Throwable $thrown) {
            Logger::getInstance()->logThrowable($thrown);
            $integration->last_error = mb_substr($thrown->getMessage(), 0, 500);
        }

        $integration->save();
    }

    protected function provider(Integration $integration): PullRequestProvider
    {
        return $this->integrations->providerFor($integration);
    }

    /**
     * @return array{stored: array<string,PullRequest>, open: list<array{0: PullRequestData, 1: string}>, closed: list<array{0: PullRequest, 1: ?PullRequestData}>}
     */
    private function read(Integration $integration, PullRequestProvider $provider): array
    {
        $stored = [];
        foreach (PullRequest::select()->where('integration', $integration->id)->fetch() as $row)
            $stored[$this->key($row->repository, $row->external_id)] = $row;

        $open = [];
        $seen = [];

        foreach ($provider->openPullRequests() as $pullRequest) {
            $key = $this->key($pullRequest->repository, $pullRequest->externalId);
            $seen[$key] = true;
            $open[] = [$pullRequest, $this->pipeline($provider, $pullRequest, $stored[$key] ?? null)];
        }

        $closed = [];

        foreach ($stored as $key => $row) {
            if (!isset($seen[$key]) && PullRequestData::STATE_OPEN === $row->state)
                $closed[] = [$row, $provider->pullRequest($row->repository, $row->external_id)];
        }

        return ['stored' => $stored, 'open' => $open, 'closed' => $closed];
    }

    // Each status is one more call to the forge : only asked when it can have changed
    private function pipeline(PullRequestProvider $provider, PullRequestData $pullRequest, ?PullRequest $row): string
    {
        if ($row && $row->source_commit === $pullRequest->sourceCommit && !in_array($row->pipeline_status, self::UNFINISHED_PIPELINES, true))
            return $row->pipeline_status;

        return $provider->pipelineStatus($pullRequest->repository, $pullRequest->externalId);
    }

    private function apply(Integration $integration, array $changes): void
    {
        // The first run is the reference : it would otherwise log every existing pull request
        $logChanges = null !== $integration->last_synced_at;
        $reviewers = $this->storedReviewers($integration);

        foreach ($changes['open'] as [$pullRequest, $pipeline]) {
            $row = $changes['stored'][$this->key($pullRequest->repository, $pullRequest->externalId)] ?? null;
            $changed = $row ? $this->changedFields($row, $pullRequest, $pipeline, $reviewers[$row->id] ?? []) : [];

            $saved = $this->store($integration, $row, $pullRequest, $pipeline);
            if (!$row || in_array('reviewers', $changed, true))
                $this->storeReviewers($saved, $pullRequest);

            if ($logChanges && !$row)
                $this->log($integration, 'pr.added', $saved);
            elseif ($logChanges && [] !== $changed)
                $this->log($integration, 'pr.changed', $saved, ['fields' => $changed]);
        }

        foreach ($changes['closed'] as [$row, $final]) {
            if ($final) {
                $saved = $this->store($integration, $row, $final, $row->pipeline_status);
                $this->storeReviewers($saved, $final);
            } else {
                $saved = $row;
                $this->forget($row);
            }

            if ($logChanges)
                $this->log($integration, 'pr.removed', $saved, ['state' => $final?->state]);
        }
    }

    private function store(Integration $integration, ?PullRequest $row, PullRequestData $pullRequest, string $pipeline): PullRequest
    {
        $row ??= new PullRequest([
            'workspace' => $integration->workspace,
            'integration' => $integration->id,
            'external_id' => $pullRequest->externalId,
            'repository' => $pullRequest->repository,
        ]);

        $row->source_branch = $pullRequest->sourceBranch;
        $row->target_branch = $pullRequest->targetBranch;
        $row->source_commit = $pullRequest->sourceCommit;
        $row->url = $pullRequest->url;
        $row->title = $pullRequest->title;
        $row->author = $pullRequest->author;
        $row->state = $pullRequest->state;
        $row->draft = $pullRequest->draft;
        $row->comment_count = $pullRequest->commentCount;
        $row->pipeline_status = $pipeline;
        $row->synced_at = date('Y-m-d H:i:s');

        return $row->save();
    }

    /**
     * @param array<string,string> $reviewers Status by external user id
     *
     * @return list<string>
     */
    private function changedFields(PullRequest $row, PullRequestData $pullRequest, string $pipeline, array $reviewers): array
    {
        $current = array_map(fn (array $reviewer) => $reviewer['status'], $pullRequest->reviewers);
        ksort($current);
        ksort($reviewers);

        return array_keys(array_filter([
            'title' => $row->title !== $pullRequest->title,
            'state' => $row->state !== $pullRequest->state,
            'draft' => (bool) $row->draft !== $pullRequest->draft,
            'source_branch' => $row->source_branch !== $pullRequest->sourceBranch,
            'target_branch' => $row->target_branch !== $pullRequest->targetBranch,
            'source_commit' => $row->source_commit !== $pullRequest->sourceCommit,
            'comment_count' => (int) $row->comment_count !== $pullRequest->commentCount,
            'pipeline_status' => $row->pipeline_status !== $pipeline,
            'reviewers' => $reviewers !== $current,
        ]));
    }

    /**
     * @return array<int,array<string,string>> Status by external user id, by pull request id
     */
    private function storedReviewers(Integration $integration): array
    {
        $reviewers = [];
        $approvals = PullRequestApprovals::select()->whereRaw("pull_request IN (SELECT id FROM pull_request WHERE integration = {$integration->id})")->fetch();

        foreach ($approvals as $approval)
            $reviewers[$approval->pull_request][$approval->external_user_id] = $approval->status;

        return $reviewers;
    }

    private function storeReviewers(PullRequest $row, PullRequestData $pullRequest): void
    {
        PullRequestApprovals::deleteWhere(['pull_request' => $row->id]);

        foreach ($pullRequest->reviewers as $id => $reviewer) {
            (new PullRequestApprovals([
                'pull_request' => $row->id,
                'external_user_id' => (string) $id,
                'display_name' => $reviewer['name'],
                'status' => $reviewer['status'],
            ]))->save();
        }
    }

    private function forget(PullRequest $row): void
    {
        PullRequestApprovals::deleteWhere(['pull_request' => $row->id]);
        PullRequest::deleteId($row->id);
    }

    private function log(Integration $integration, string $type, PullRequest $row, array $details = []): void
    {
        $this->history->log($integration->workspace, $type, [
            'title' => $row->title,
            'repository' => $row->repository,
            'url' => $row->url,
            ...$details,
        ], HistoryService::ORIGIN_INTEGRATION);
    }

    private function key(string $repository, string $externalId): string
    {
        return "{$repository}#{$externalId}";
    }
}
