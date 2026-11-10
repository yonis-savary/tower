<?php

namespace App\Integrations\Jira;

use App\Integrations\IntegrationChange;
use App\Integrations\IntegrationEvents;
use App\Integrations\IntegrationService;
use App\Integrations\Jira\Changes\StatusChanged;
use App\Models\History;
use App\Models\Integration;
use App\Models\JiraIssue;
use App\Models\Task;
use App\Services\EventService;
use App\Services\HistoryService;
use App\Services\TaskService;
use Cube\Data\Bunch;

class JiraIssueService
{
    public const DISCUSS_EVENT = 'ai.discuss';
    private const TASK_TITLE_LENGTH = 255;

    public function __construct(
        protected IntegrationService $integrations,
        protected TaskService $tasks,
        protected EventService $events,
        protected IntegrationEvents $integrationEvents,
        protected StatusChanged $statusChanged,
    ) {}

    /**
     * @return Bunch<int,JiraIssue>
     */
    public function listIssues(int $workspace): Bunch
    {
        return Bunch::wrap(JiraIssue::select()->where('workspace', $workspace)->order('id', 'DESC')->fetch());
    }

    /**
     * @return list<array{name: string, statuses: list<string>}>
     */
    public function boardColumns(int $workspace): array
    {
        $integration = $this->integrations->findJira($workspace);

        return $integration ? $this->integrations->settingsOf($integration)['columns'] ?? [] : [];
    }

    public function findIssue(int $workspace, int $id): ?JiraIssue
    {
        $issue = JiraIssue::find($id);

        return $issue && $issue->workspace === $workspace ? $issue : null;
    }

    /**
     * @return list<JiraTransition>
     */
    public function transitions(JiraIssue $issue): array
    {
        return $this->provider($issue)->transitions($issue->issue_key);
    }

    /**
     * @return ?string Jira's refusal, null once applied
     */
    public function transition(JiraIssue $issue, int $user, string $transitionId): ?string
    {
        $provider = $this->provider($issue);
        $refusal = $provider->transition($issue->issue_key, $transitionId);
        if (null !== $refusal)
            return $refusal;

        $current = $provider->issue($issue->issue_key);
        if (!$current)
            return null;

        $before = $issue->toData();
        // Stored right away, so the next sync sees no change and does not announce it a second time
        $issue->copyFrom($current)->save();

        $this->integrationEvents->announce(array_map(
            fn (IntegrationChange $change) => $this->integrationEvents->logIssue($issue, $change, HistoryService::ORIGIN_USER, $user),
            $this->statusChanged->detect($before, $current),
        ));

        return null;
    }

    /**
     * @return ?Task null when the issue already has a task
     */
    public function createTask(JiraIssue $issue, int $user): ?Task
    {
        if (Task::findWhere(['jira_issue' => $issue->id]))
            return null;

        $title = mb_substr("{$issue->issue_key} {$issue->summary}", 0, self::TASK_TITLE_LENGTH);

        return $this->tasks->createTask($issue->workspace, $user, $title, $issue->url, null, $issue->id);
    }

    /**
     * @return ?History null when Jira does not know the issue anymore
     */
    public function discuss(JiraIssue $issue, int $user): ?History
    {
        $content = $this->provider($issue)->issueContent($issue->issue_key);
        if (!$content)
            return null;

        $payload = [
            'source' => 'jira',
            'key' => $content->issue->key,
            'url' => $content->issue->url,
            'summary' => $content->issue->summary,
            'project' => $content->issue->project,
            'issue_type' => $content->issue->issueType,
            'priority' => $content->issue->priority,
            'status' => $content->issue->status,
            'sprint' => $content->issue->sprint?->name,
            'assignee' => $content->assignee,
            'reporter' => $content->reporter,
            'labels' => $content->labels,
            'description' => $content->description,
            'comments' => $content->comments,
        ];

        return $this->events->triggerAsUser(
            $issue->workspace,
            $user,
            self::DISCUSS_EVENT,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    protected function provider(JiraIssue $issue): JiraProvider
    {
        return $this->integrations->jiraProviderFor(Integration::find($issue->integration));
    }
}
