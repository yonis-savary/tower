<?php

namespace App\Integrations;

use App\Channels\DevicesChannel;
use App\Models\History;
use App\Models\JiraIssue;
use App\Models\PullRequest;
use App\Services\HistoryService;

class IntegrationEvents
{
    public function __construct(
        protected HistoryService $history,
        protected DevicesChannel $devices,
    ) {}

    public function logPullRequest(PullRequest $row, IntegrationChange $change): History
    {
        return $this->history->log($row->workspace, $change->type, [
            'source' => 'bitbucket',
            'id' => $row->external_id,
            'repository' => $row->repository,
            'title' => $row->title,
            'url' => $row->url,
            ...$change->details,
        ], HistoryService::ORIGIN_INTEGRATION);
    }

    public function logIssue(JiraIssue $row, IntegrationChange $change, string $origin = HistoryService::ORIGIN_INTEGRATION, ?int $user = null): History
    {
        return $this->history->log($row->workspace, $change->type, [
            'source' => 'jira',
            'key' => $row->issue_key,
            'summary' => $row->summary,
            'url' => $row->url,
            ...$change->details,
        ], $origin, $user);
    }

    /**
     * Only once the lines are committed : the websocket server writes the deliveries against them.
     *
     * @param list<History> $lines
     */
    public function announce(array $lines): void
    {
        foreach ($lines as $line)
            $this->devices->sendEvent($line->workspace, $line->id, $line->type, $line->details);
    }
}
