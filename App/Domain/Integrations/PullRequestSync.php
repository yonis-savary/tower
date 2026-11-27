<?php

namespace App\Domain\Integrations;

use App\Models\History;
use App\Models\Integration;
use App\Models\PullRequest;
use App\Models\PullRequestApprovals;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;
use Cube\Env\Logger\Logger;

/**
 * Brings the stored pull requests in line with the forge, and writes the differences in the history.
 */
class PullRequestSync
{
    private const UNFINISHED_PIPELINES = [PullRequestData::PIPELINE_PENDING, PullRequestData::PIPELINE_RUNNING];

    /** @var ?Bunch<int,PullRequestChangeDetector> */
    private ?Bunch $detectors = null;

    public function __construct(
        protected IntegrationService $integrations,
        protected IntegrationEvents $events,
    ) {}

    public function syncAll(): void
    {
        foreach (Integration::select()->where('type', IntegrationService::TYPE_BITBUCKET)->fetch() as $integration)
            $this->sync($integration);
    }

    public function sync(Integration $integration): void
    {
        try {
            // Everything is read first : a failing forge must leave the stored state untouched
            $changes = $this->read($integration, $this->provider($integration));

            $lines = [];
            $failure = Database::getInstance()->transaction(function () use ($integration, $changes, &$lines) {
                $lines = $this->apply($integration, $changes);
            });
            if ($failure)
                throw $failure;

            $this->events->announce($lines);

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

    /**
     * @return list<History> The lines to announce
     */
    private function apply(Integration $integration, array $changes): array
    {
        // The first run is the reference : it would otherwise log every existing pull request
        $logChanges = null !== $integration->last_synced_at;
        $reviewers = $this->storedReviewers($integration);
        $lines = [];

        foreach ($changes['open'] as [$pullRequest, $pipeline]) {
            $row = $changes['stored'][$this->key($pullRequest->repository, $pullRequest->externalId)] ?? null;
            $before = $row?->toState($reviewers[$row->id] ?? []);
            $found = $before ? $this->detect($before, new PullRequestState($pullRequest, $pipeline)) : [new IntegrationChange('pr.opened')];

            $saved = $this->store($integration, $row, $pullRequest, $pipeline);
            if (!$before || $this->statuses($before->pullRequest->reviewers) !== $this->statuses($pullRequest->reviewers))
                $this->storeReviewers($saved, $pullRequest);

            if ($logChanges)
                array_push($lines, ...array_map(fn (IntegrationChange $change) => $this->events->logPullRequest($saved, $change), $found));
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
                $lines[] = $this->events->logPullRequest($saved, $this->closing($final));
        }

        return $lines;
    }

    /**
     * @return list<IntegrationChange>
     */
    private function detect(PullRequestState $before, PullRequestState $after): array
    {
        $this->detectors ??= Bunch::fromImplements(PullRequestChangeDetector::class);

        $changes = [];
        foreach ($this->detectors as $detector)
            array_push($changes, ...$detector->detect($before, $after));

        return $changes;
    }

    private function closing(?PullRequestData $final): IntegrationChange
    {
        return PullRequestData::STATE_MERGED === $final?->state
            ? new IntegrationChange('pr.merged')
            : new IntegrationChange('pr.declined', ['state' => $final?->state]);
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
     * @param array<string,array{name: string, status: string}> $reviewers
     *
     * @return array<string,string>
     */
    private function statuses(array $reviewers): array
    {
        $statuses = array_map(fn (array $reviewer) => $reviewer['status'], $reviewers);
        ksort($statuses);

        return $statuses;
    }

    /**
     * @return array<int,array<string,array{name: string, status: string}>> By pull request id, then external user id
     */
    private function storedReviewers(Integration $integration): array
    {
        $reviewers = [];
        $approvals = PullRequestApprovals::select()->whereRaw("pull_request IN (SELECT id FROM pull_request WHERE integration = {$integration->id})")->fetch();

        foreach ($approvals as $approval)
            $reviewers[$approval->pull_request][$approval->external_user_id] = ['name' => $approval->display_name, 'status' => $approval->status];

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

    private function key(string $repository, string $externalId): string
    {
        return "{$repository}#{$externalId}";
    }
}
