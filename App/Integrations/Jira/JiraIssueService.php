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
use Cube\Data\Database\Query;

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
     * @return Bunch<int,JiraIssue> With the links to their profiles
     */
    public function listIssues(int $workspace, bool $mine = false, ?string $statusCategory = null, ?int $profile = null): Bunch
    {
        $account = $mine ? $this->accountOf($workspace) : null;
        if ($mine && null === $account)
            return Bunch::wrap([]);

        $query = JiraIssue::select(['profiles'])->where('workspace', $workspace)->order('id', 'DESC');
        if (null !== $statusCategory)
            $query->where('status_category', $statusCategory);
        if (null !== $account)
            $query->where('assignee_id', $account);
        if (null !== $profile)
            $query->whereIn('id', Query::select('jira_issue_profile')->selectField('jira_issue', 'jira_issue_profile')->where('jira_profile', $profile, table: 'jira_issue_profile'));

        return Bunch::wrap($query->fetch());
    }

    public function accountOf(int $workspace): ?string
    {
        $integration = $this->integrations->findJira($workspace);

        return $integration ? $this->integrations->jiraAccountOf($integration) : null;
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
        // A subtask keeps the sprint and the assignee the board gives it : only its status is taken from the reading
        null === $issue->parent ? $issue->copyFrom($current) : $issue->copyStatusFrom($current);
        $issue->save();

        if ($issue->assignee_id !== $this->integrations->jiraAccountOf(Integration::find($issue->integration)))
            return null;

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

        return $this->events->triggerAsUser(
            $issue->workspace,
            $user,
            self::DISCUSS_EVENT,
            json_encode(['source' => 'jira', ...$this->contentPayload($content)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    public function content(int $workspace, string $key): ?JiraIssueContent
    {
        return $this->providerOf($workspace)?->issueContent($key);
    }

    public function attachment(int $workspace, string $id): ?JiraAttachment
    {
        return $this->providerOf($workspace)?->attachment($id);
    }

    public function attachmentContent(int $workspace, JiraAttachment $attachment): string
    {
        return $this->providerOf($workspace)->attachmentContent($attachment->id);
    }

    /**
     * @return array<string,mixed>
     */
    public function contentPayload(JiraIssueContent $content): array
    {
        return [
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
            'attachments' => array_map(fn (JiraAttachment $attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mimeType,
                'size' => $attachment->size,
                'created' => $attachment->created,
                'author' => $attachment->author,
            ], $content->attachments),
        ];
    }

    private function providerOf(int $workspace): ?JiraProvider
    {
        $integration = $this->integrations->findJira($workspace);

        return $integration ? $this->integrations->jiraProviderFor($integration) : null;
    }

    protected function provider(JiraIssue $issue): JiraProvider
    {
        return $this->integrations->jiraProviderFor(Integration::find($issue->integration));
    }
}
