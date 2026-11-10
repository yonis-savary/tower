<?php

namespace App\Integrations\Jira;

use App\Integrations\IntegrationChange;
use App\Integrations\IntegrationEvents;
use App\Integrations\IntegrationService;
use App\Models\History;
use App\Models\Integration;
use App\Models\JiraIssue;
use App\Models\Task;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;
use Cube\Env\Logger\Logger;

class JiraIssueSync
{
    /** @var ?Bunch<int,JiraIssueChangeDetector> */
    private ?Bunch $detectors = null;

    public function __construct(
        protected IntegrationService $integrations,
        protected IntegrationEvents $events,
    ) {}

    public function syncAll(): void
    {
        foreach (Integration::select()->where('type', IntegrationService::TYPE_JIRA)->fetch() as $integration)
            $this->sync($integration);
    }

    public function sync(Integration $integration): void
    {
        try {
            // Everything is read first : a failing Jira must leave the stored state untouched
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

    protected function provider(Integration $integration): JiraProvider
    {
        return $this->integrations->jiraProviderFor($integration);
    }

    /**
     * @return array{columns: list<JiraColumn>, stored: array<string,JiraIssue>, assigned: list<JiraIssueData>, left: list<array{0: JiraIssue, 1: ?JiraIssueData}>}
     */
    private function read(Integration $integration, JiraProvider $provider): array
    {
        $stored = [];
        foreach (JiraIssue::select()->where('integration', $integration->id)->fetch() as $row)
            $stored[$row->external_id] = $row;

        $assigned = $provider->assignedIssues();
        $seen = array_flip(array_map(fn (JiraIssueData $issue) => $issue->externalId, $assigned));

        $left = [];
        foreach ($stored as $externalId => $row) {
            if ($row->tracked && !isset($seen[$externalId]))
                $left[] = [$row, $provider->issue($row->issue_key)];
        }

        return ['columns' => $provider->boardColumns(), 'stored' => $stored, 'assigned' => $assigned, 'left' => $left];
    }

    /**
     * @return list<History> The lines to announce
     */
    private function apply(Integration $integration, array $changes): array
    {
        // The first run is the reference : it would otherwise log every existing issue
        $logChanges = null !== $integration->last_synced_at;
        $this->storeColumns($integration, $changes['columns']);
        $lines = [];

        foreach ($changes['assigned'] as $issue) {
            $row = $changes['stored'][$issue->externalId] ?? null;
            $found = $row && $row->tracked ? $this->detect($row->toData(), $issue) : [new IntegrationChange('jira.issue.assigned')];

            $row ??= new JiraIssue(['workspace' => $integration->workspace, 'integration' => $integration->id]);
            $row->copyFrom($issue);
            $row->tracked = true;
            $row->save();

            if ($logChanges)
                array_push($lines, ...array_map(fn (IntegrationChange $change) => $this->events->logIssue($row, $change), $found));
        }

        foreach ($changes['left'] as [$row, $final]) {
            if ($final)
                $row->copyFrom($final);

            if ($logChanges)
                $lines[] = $this->events->logIssue($row, $this->leaving($final));

            // A task keeps showing the status of its issue : the row stays, only out of the board
            if (!Task::findWhere(['jira_issue' => $row->id])) {
                JiraIssue::deleteId($row->id);
                continue;
            }

            $row->tracked = false;
            $row->save();
        }

        return $lines;
    }

    /**
     * @return list<IntegrationChange>
     */
    private function detect(JiraIssueData $before, JiraIssueData $after): array
    {
        $this->detectors ??= Bunch::fromImplements(JiraIssueChangeDetector::class);

        $changes = [];
        foreach ($this->detectors as $detector)
            array_push($changes, ...$detector->detect($before, $after));

        return $changes;
    }

    private function leaving(?JiraIssueData $final): IntegrationChange
    {
        return JiraIssueData::CATEGORY_DONE === $final?->statusCategory
            ? new IntegrationChange('jira.issue.done', ['status' => $final->status])
            : new IntegrationChange('jira.issue.unassigned', ['status' => $final?->status]);
    }

    /**
     * @param list<JiraColumn> $columns
     */
    private function storeColumns(Integration $integration, array $columns): void
    {
        $integration->settings = json_encode([
            ...$this->integrations->settingsOf($integration),
            'columns' => array_map(fn (JiraColumn $column) => ['name' => $column->name, 'statuses' => $column->statusIds], $columns),
        ], JSON_THROW_ON_ERROR);
    }
}
