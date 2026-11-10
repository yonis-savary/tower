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
     * @return array{account: JiraUser, columns: list<JiraColumn>, stored: array<string,JiraIssue>, board: list<JiraIssueData>, left: list<array{0: JiraIssue, 1: ?JiraIssueData}>}
     */
    private function read(Integration $integration, JiraProvider $provider): array
    {
        $account = $provider->myself();
        $stored = [];
        foreach (JiraIssue::select()->where('integration', $integration->id)->fetch() as $row)
            $stored[$row->external_id] = $row;

        $board = $provider->boardIssues();
        $seen = array_flip(array_map(fn (JiraIssueData $issue) => $issue->externalId, $board));

        $left = [];
        foreach ($stored as $externalId => $row) {
            if (!$row->tracked || isset($seen[$externalId]))
                continue;
            // Only the issues of the account are announced : the others need no last reading
            $left[] = [$row, $row->assignee_id === $account->accountId ? $provider->issue($row->issue_key) : null];
        }

        return ['account' => $account, 'columns' => $provider->boardColumns(), 'stored' => $stored, 'board' => $board, 'left' => $left];
    }

    /**
     * @return list<History> The lines to announce
     */
    private function apply(Integration $integration, array $changes): array
    {
        // The first run is the reference, and so is the first one knowing the account : it would otherwise log every existing issue
        $logChanges = null !== $integration->last_synced_at && isset($this->integrations->settingsOf($integration)['account']);
        $account = $changes['account']->accountId;
        $this->storeBoard($integration, $changes['columns'], $account);
        $lines = [];

        foreach ($changes['board'] as $issue) {
            $row = $changes['stored'][$issue->externalId] ?? null;
            $found = $this->changesOf($row && $row->tracked ? $row->toData() : null, $issue, $account);

            $row ??= new JiraIssue(['workspace' => $integration->workspace, 'integration' => $integration->id]);
            $row->copyFrom($issue);
            $row->tracked = true;
            $row->save();

            if ($logChanges)
                array_push($lines, ...array_map(fn (IntegrationChange $change) => $this->events->logIssue($row, $change), $found));
        }

        foreach ($changes['left'] as [$row, $final]) {
            $wasMine = $row->assignee_id === $account;
            if ($final)
                $row->copyFrom($final);

            if ($logChanges && $wasMine)
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
    private function changesOf(?JiraIssueData $before, JiraIssueData $after, string $account): array
    {
        // The other issues of the board are shown, never announced
        $wasMine = $before?->assignee?->accountId === $account;
        $isMine = $after->assignee?->accountId === $account;

        return match (true) {
            $isMine && !$wasMine => [new IntegrationChange('jira.issue.assigned')],
            $wasMine && !$isMine => [new IntegrationChange('jira.issue.unassigned', ['status' => $after->status, 'assignee' => $after->assignee?->name])],
            $isMine => $this->detect($before, $after),
            default => [],
        };
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
    private function storeBoard(Integration $integration, array $columns, string $account): void
    {
        $integration->settings = json_encode([
            ...$this->integrations->settingsOf($integration),
            'columns' => array_map(fn (JiraColumn $column) => ['name' => $column->name, 'statuses' => $column->statusIds], $columns),
            'account' => $account,
        ], JSON_THROW_ON_ERROR);
    }
}
