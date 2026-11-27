<?php

namespace App\Domain\Integrations\Jira;

use App\Domain\Event\EventService;
use App\Domain\History\HistoryService;
use App\Domain\Integrations\IntegrationChange;
use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Integrations\IntegrationService;
use App\Domain\Integrations\Jira\Changes\StatusChanged;
use App\Domain\Task\TaskService;
use App\Domain\Task\TimelineSpan;
use App\Models\History;
use App\Models\Integration;
use App\Models\JiraIssue;
use App\Models\JiraProfile;
use App\Models\Task;
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
        if (null !== $profile) {
            $issuesOfProfile = Query::select('jira_issue_profile')
                ->selectField('jira_issue', 'jira_issue_profile')
                ->where('jira_profile', $profile, table: 'jira_issue_profile');
            $query->whereIn('id', $issuesOfProfile);
        }

        return $query->toBunch();
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

        if (null !== $issue->parent || $issue->assignee_id !== $this->integrations->jiraAccountOf(Integration::find($issue->integration)))
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

    /**
     * The issues the account of the integration worked on during a period, read from Jira, without
     * those a task of the workspace is linked to : the task already tells their story. A status is
     * classified by the boards of the profiles : their first column is to do, their last one done.
     *
     * @param string $start first second of the period, Y-m-d H:i:s in server time
     * @param string $end last second of the period, Y-m-d H:i:s in server time
     *
     * @return ?array{issues:list<array>,truncated:bool} Oldest start first, null without Jira integration
     */
    public function workedIssues(int $workspace, string $start, string $end): ?array
    {
        $provider = $this->providerOf($workspace);
        if (!$provider)
            return null;

        $worked = $provider->workedIssues($start, $end);
        $linkedIssues = Query::select('task')
            ->selectField('jira_issue', 'task')
            ->where('workspace', $workspace, table: 'task');
        $issuesWithTask = JiraIssue::select()
            ->whereIn('id', $linkedIssues)
            ->fetch();
        $withTask = array_flip(array_map(fn (JiraIssue $issue) => $issue->issue_key, $issuesWithTask));
        $categories = $this->boardCategories($workspace);
        $siteCategories = null;
        $categoryOf = function (?string $statusId) use ($categories, $provider, &$siteCategories): string {
            if (null !== $statusId && isset($categories[$statusId]))
                return $categories[$statusId];

            // A status no board shows : Jira's own category, read once
            $siteCategories ??= $provider->statusCategories();

            return $siteCategories[$statusId ?? ''] ?? TimelineSpan::TODO;
        };

        $issues = [];
        foreach ($worked['issues'] as $issue) {
            if (isset($withTask[$issue->key]))
                continue;

            $category = $categories[$issue->statusId] ?? $issue->statusCategory;
            $changes = array_map(fn (array $change) => [
                'at' => $change['at'],
                'from' => $change['from'],
                'to' => $change['to'],
                'from_category' => $categoryOf($change['from_id']),
                'to_category' => $categoryOf($change['to_id']),
            ], $issue->changes);

            $span = TimelineSpan::place($changes, $category, $issue->created, $issue->resolved, strtotime($start), strtotime($end));
            if (!$span)
                continue;

            $issues[] = [
                'key' => $issue->key,
                'summary' => $issue->summary,
                'url' => $issue->url,
                'project' => $issue->project,
                'issue_type' => $issue->issueType,
                'status' => $issue->status,
                'status_category' => $category,
                ...$span,
            ];
        }
        usort($issues, fn (array $a, array $b) => strtotime($a['started_at'] ?? $a['created_at'] ?? 'now') <=> strtotime($b['started_at'] ?? $b['created_at'] ?? 'now'));

        return ['issues' => $issues, 'truncated' => $worked['truncated']];
    }

    /**
     * @return array<string,string> The category of the statuses the boards of the profiles show, by status id
     */
    private function boardCategories(int $workspace): array
    {
        $categories = [];
        $profiles = JiraProfile::select()
            ->where('workspace', $workspace)
            ->order('id', 'ASC')
            ->fetch();
        foreach ($profiles as $profile) {
            $columns = json_decode((string) $profile->columns, true) ?: [];
            foreach ($columns as $index => $column) {
                $category = match (true) {
                    count($columns) - 1 === $index => TimelineSpan::DONE,
                    0 === $index => TimelineSpan::TODO,
                    default => TimelineSpan::IN_PROGRESS,
                };
                foreach ($column['statuses'] ?? [] as $id)
                    $categories[(string) $id] ??= $category;
            }
            foreach (json_decode((string) $profile->done_statuses, true) ?: [] as $id)
                $categories[(string) $id] = TimelineSpan::DONE;
        }

        return $categories;
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
