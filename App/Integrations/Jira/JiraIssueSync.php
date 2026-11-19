<?php

namespace App\Integrations\Jira;

use App\Integrations\IntegrationChange;
use App\Integrations\IntegrationEvents;
use App\Integrations\IntegrationService;
use App\Models\History;
use App\Models\Integration;
use App\Models\JiraIssue;
use App\Models\JiraIssueProfile;
use App\Models\JiraProfile;
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
        protected JiraProfileService $profiles,
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
     * @return array{account: JiraUser, profiles: list<JiraProfile>, readings: array<int,JiraProfileReading>, stored: array<string,JiraIssue>, board: array<string,JiraIssueData>, profilesOf: array<string,list<int>>, left: list<array{0: JiraIssue, 1: ?JiraIssueData, 2: list<string>}>}
     */
    private function read(Integration $integration, JiraProvider $provider): array
    {
        $account = $provider->myself();
        $stored = [];
        foreach (JiraIssue::select()->where('integration', $integration->id)->fetch() as $row)
            $stored[$row->external_id] = $row;

        $profiles = JiraProfile::select()->where('integration', $integration->id)->order('id', 'ASC')->fetch();
        $readings = [];
        $board = [];
        $profilesOf = [];
        foreach ($profiles as $profile) {
            $reading = $readings[$profile->id] = $this->readProfile($provider, $profile);
            foreach ($reading->issues as $issue) {
                $board[$issue->externalId] ??= $issue;
                foreach ([$issue, ...$issue->subtasks] as $shown)
                    $profilesOf[$shown->externalId][] = $profile->id;
            }
        }
        // A board without a filter on them also lists the subtasks on their own : they are stored under their parent only
        $subtasks = array_merge([], ...array_map(fn (JiraIssueData $issue) => array_map(fn (JiraIssueData $subtask) => $subtask->externalId, $issue->subtasks), array_values($board)));
        $board = array_diff_key($board, array_flip($subtasks));

        $linked = $this->profiles->profilesByIssue($integration->id);
        $left = [];
        foreach ($stored as $externalId => $row) {
            if (!$row->tracked || isset($profilesOf[$externalId]))
                continue;
            $doneStatuses = array_merge([], ...array_map(fn (int $profile) => $readings[$profile]->doneStatuses, $linked[$row->id] ?? []));
            // Only the issues of the account are announced : the others need no last reading
            $left[] = [$row, $row->assignee_id === $account->accountId ? $provider->issue($row->issue_key) : null, $doneStatuses];
        }
        // A parent can only be deleted once its subtasks are
        usort($left, fn (array $a, array $b) => (null === $a[0]->parent) <=> (null === $b[0]->parent));

        return ['account' => $account, 'profiles' => $profiles, 'readings' => $readings, 'stored' => $stored, 'board' => $board, 'profilesOf' => $profilesOf, 'left' => $left];
    }

    private function readProfile(JiraProvider $provider, JiraProfile $profile): JiraProfileReading
    {
        $columns = $provider->boardColumns($profile->board);
        // The last column of the board is its "done", whatever the category Jira gives its statuses
        $doneStatuses = [] === $columns ? [] : end($columns)->statusIds;

        return new JiraProfileReading(
            boardName: $provider->boardName($profile->board),
            columns: $columns,
            doneStatuses: $doneStatuses,
            issues: $provider->boardIssues($profile->board, $profile->jql, $doneStatuses),
        );
    }

    /**
     * @return list<History> The lines to announce
     */
    private function apply(Integration $integration, array $changes): array
    {
        // The first run is the reference, and so is the first one knowing the account : it would otherwise log every existing issue
        $logChanges = null !== $integration->last_synced_at && null !== $this->integrations->jiraAccountOf($integration);
        // Likewise, the issues a new profile brings were not assigned in the meantime
        $referenceProfiles = array_values(array_map(fn (JiraProfile $profile) => $profile->id, array_filter($changes['profiles'], fn (JiraProfile $profile) => null !== $profile->synced_at)));
        $account = $changes['account']->accountId;
        $this->storeAccount($integration, $account);
        foreach ($changes['profiles'] as $profile)
            $this->storeProfile($profile, $changes['readings'][$profile->id]);

        $lines = [];
        // An issue can be read both on its own and as the subtask of another : keyed by row, it is linked once
        $links = [];

        foreach ($changes['board'] as $issue) {
            $row = $changes['stored'][$issue->externalId] ?? null;
            $known = $row && $row->tracked;
            $found = $this->changesOf($known ? $row->toData() : null, $issue, $account);

            $row ??= new JiraIssue(['workspace' => $integration->workspace, 'integration' => $integration->id]);
            $row->copyFrom($issue);
            $row->tracked = true;
            $row->save();
            $links[$row->id] = $changes['profilesOf'][$issue->externalId];

            if ($logChanges && ($known || [] !== array_intersect($changes['profilesOf'][$issue->externalId], $referenceProfiles)))
                array_push($lines, ...array_map(fn (IntegrationChange $change) => $this->events->logIssue($row, $change), $found));

            foreach ($issue->subtasks as $subtask)
                $links[$this->storeSubtask($integration, $changes['stored'][$subtask->externalId] ?? null, $subtask, $row)->id] = $changes['profilesOf'][$subtask->externalId];
        }
        $this->relink($changes['profiles'], $links);

        foreach ($changes['left'] as [$row, $final, $doneStatuses]) {
            $wasMine = $row->assignee_id === $account;
            if ($final)
                $row->copyFrom($final);

            if ($logChanges && $wasMine)
                $lines[] = $this->events->logIssue($row, $this->leaving($final, $doneStatuses));

            // A task keeps showing the status of its issue : the row stays, only out of the profiles
            if (!Task::findWhere(['jira_issue' => $row->id])) {
                JiraIssue::deleteId($row->id);
                continue;
            }

            $row->tracked = false;
            $row->parent = null;
            $row->save();
        }

        return $lines;
    }

    /**
     * @param list<JiraProfile>      $profiles
     * @param array<int,list<int>> $links    The ids of the profiles showing each issue, by issue id
     */
    private function relink(array $profiles, array $links): void
    {
        JiraIssueProfile::delete()->whereIn('jira_profile', array_map(fn (JiraProfile $profile) => $profile->id, $profiles))->fetch();

        foreach ($links as $issue => $profileIds)
            foreach (array_unique($profileIds) as $profile)
                JiraIssueProfile::fromArray(['jira_issue' => $issue, 'jira_profile' => $profile])->save();
    }

    // Jira does not give the assignee of a subtask : it is shown under its parent, never announced
    private function storeSubtask(Integration $integration, ?JiraIssue $row, JiraIssueData $subtask, JiraIssue $parent): JiraIssue
    {
        $row ??= new JiraIssue(['workspace' => $integration->workspace, 'integration' => $integration->id]);
        $row->copyFrom($subtask);
        $row->parent = $parent->id;
        $row->tracked = true;

        return $row->save();
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

    /**
     * @param list<string> $doneStatuses
     */
    private function leaving(?JiraIssueData $final, array $doneStatuses): IntegrationChange
    {
        return $final && (JiraIssueData::CATEGORY_DONE === $final->statusCategory || in_array($final->statusId, $doneStatuses, true))
            ? new IntegrationChange('jira.issue.done', ['status' => $final->status])
            : new IntegrationChange('jira.issue.unassigned', ['status' => $final?->status]);
    }

    private function storeAccount(Integration $integration, string $account): void
    {
        $integration->settings = json_encode([...$this->integrations->settingsOf($integration), 'account' => $account], JSON_THROW_ON_ERROR);
    }

    private function storeProfile(JiraProfile $profile, JiraProfileReading $reading): void
    {
        $profile->board_name = $reading->boardName;
        $profile->columns = json_encode(array_map(fn (JiraColumn $column) => ['name' => $column->name, 'statuses' => $column->statusIds], $reading->columns), JSON_THROW_ON_ERROR);
        $profile->done_statuses = json_encode($reading->doneStatuses, JSON_THROW_ON_ERROR);
        $profile->synced_at = date('Y-m-d H:i:s');
        $profile->save();
    }
}
