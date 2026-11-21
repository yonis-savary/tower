<?php

namespace App\Services;

use App\Models\History;
use App\Models\Sprint;
use App\Models\SprintLink;
use App\Models\Task;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;

class TaskService
{
    public const STATUS_TODO = 'todo';
    public const STATUS_DONE = 'done';
    public const STATUSES = [self::STATUS_TODO, 'in_progress', 'in_review', 'finalizing', self::STATUS_DONE, 'cancelled'];

    public const SPRINT_PLANNED = 'planned';
    public const SPRINT_ACTIVE = 'active';
    public const SPRINT_CLOSED = 'closed';

    public function __construct(
        protected HistoryService $history,
        protected EventAnnouncer $announcer,
    ) {}

    /**
     * @return Bunch<int,Task>
     */
    public function listTasks(int $workspace): Bunch
    {
        return Bunch::wrap(Task::select()->where('workspace', $workspace)->order('position', 'ASC')->order('id', 'ASC')->fetch());
    }

    public function findTask(int $workspace, int $id): ?Task
    {
        $task = Task::find($id);

        return $task && $task->workspace === $workspace ? $task : null;
    }

    /**
     * @return ?Task null when the sprint cannot receive tasks
     */
    public function createTask(int $workspace, int $user, string $title, string $description, ?int $sprint, ?int $jiraIssue = null, ?string $link = null): ?Task
    {
        if (!$this->acceptsTasks($workspace, $sprint))
            return null;

        $now = date('Y-m-d H:i:s');
        $task = (new Task([
            'workspace' => $workspace,
            'sprint' => $sprint,
            'title' => $title,
            'description' => $description,
            'status' => self::STATUS_TODO,
            'position' => $this->nextPosition($workspace, $sprint),
            'jira_issue' => $jiraIssue,
            'link' => $link,
            'created_at' => $now,
            'updated_at' => $now,
        ]))->save();

        $this->log($task, 'task.created', $user);

        return $task;
    }

    public function updateTask(Task $task, int $user, string $title, string $description, ?string $link): Task
    {
        if ($task->title === $title && $task->description === $description && $task->link === $link)
            return $task;

        $previous = $task->title;
        $task->title = $title;
        $task->description = $description;
        $task->link = $link;
        $task->updated_at = date('Y-m-d H:i:s');
        $task->save();

        $this->log($task, 'task.updated', $user, ['previous_title' => $previous]);

        return $task;
    }

    /**
     * @param ?list<int> $ordered The tasks of the destination list, in their new order. null keeps the positions
     *
     * @return ?Task null when the sprint cannot receive tasks, or a listed task is not in this workspace
     */
    public function moveTask(Task $task, int $user, ?int $sprint, string $status, ?array $ordered): ?Task
    {
        $sprintChanged = $task->sprint !== $sprint;
        if ($sprintChanged && !$this->acceptsTasks($task->workspace, $sprint))
            return null;

        $neighbours = [];
        if (null !== $ordered) {
            $ordered = array_values(array_unique($ordered));
            if (!in_array($task->id, $ordered, true))
                return null;

            $neighbours = Task::select()->where('workspace', $task->workspace)->whereIn('id', $ordered)->fetch();
            if (count($neighbours) !== count($ordered))
                return null;
        }

        $announcements = [];
        $failure = Database::getInstance()->transaction(function () use ($task, $user, $sprint, $status, $ordered, $neighbours, $sprintChanged, &$announcements) {
            $previousStatus = $task->status;
            $previousSprint = $task->sprint;
            $statusChanged = $previousStatus !== $status;

            $task->status = $status;
            $task->sprint = $sprint;

            if ($statusChanged || $sprintChanged)
                $task->updated_at = date('Y-m-d H:i:s');
            if ($statusChanged)
                $task->done_at = self::STATUS_DONE === $status ? date('Y-m-d H:i:s') : null;
            if ($sprintChanged && null === $ordered)
                $task->position = $this->nextPosition($task->workspace, $sprint);

            foreach ($neighbours as $neighbour) {
                $position = array_search($neighbour->id, $ordered, true);

                if ($neighbour->id === $task->id) {
                    $task->position = $position;
                } elseif ($neighbour->position !== $position) {
                    $neighbour->position = $position;
                    $neighbour->save();
                }
            }

            $task->save();

            if ($statusChanged)
                $announcements[] = $this->log($task, 'task.status_changed', $user, ['from' => $previousStatus, 'to' => $status], announce: false);
            if ($sprintChanged)
                $announcements[] = $this->log($task, 'task.sprint_changed', $user, ['from' => $this->sprintName($previousSprint), 'to' => $this->sprintName($sprint)], announce: false);
        });

        if ($failure)
            throw $failure;

        // Only once committed : the websocket server writes the deliveries against these history lines
        foreach ($announcements as [$line, $payload])
            $this->announce($line, $payload);

        return $task;
    }

    /**
     * @return Bunch<int,History> Newest first, with their author
     */
    public function historyOf(Task $task): Bunch
    {
        return Bunch::wrap(History::select(['_user'])->where('task', $task->id)->order('id', 'DESC')->fetch());
    }

    /**
     * @return Bunch<int,Sprint> Oldest first
     */
    public function listSprints(int $workspace): Bunch
    {
        return Bunch::wrap(Sprint::select(['links'])->where('workspace', $workspace)->order('id', 'ASC')->fetch());
    }

    public function findSprint(int $workspace, int $id): ?Sprint
    {
        $sprint = Sprint::find($id);

        return $sprint && $sprint->workspace === $workspace ? $sprint : null;
    }

    public function createSprint(int $workspace, int $user, string $name): Sprint
    {
        $sprint = (new Sprint([
            'workspace' => $workspace,
            'name' => $name,
            'state' => self::SPRINT_PLANNED,
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();

        $this->announceSprint($sprint, 'sprint.created', $user);

        return $sprint;
    }

    public function openSprint(Sprint $sprint, int $user): void
    {
        if (self::SPRINT_ACTIVE === $sprint->state)
            return;

        $sprint->state = self::SPRINT_ACTIVE;
        $sprint->opened_at = date('Y-m-d H:i:s');
        $sprint->closed_at = null;
        $sprint->save();

        $this->announceSprint($sprint, 'sprint.opened', $user);
    }

    /**
     * Only marks the sprint as finished : its tasks stay where they are.
     *
     * @return bool false when the sprint is not active
     */
    public function closeSprint(Sprint $sprint, int $user): bool
    {
        if (self::SPRINT_ACTIVE !== $sprint->state)
            return false;

        $sprint->state = self::SPRINT_CLOSED;
        $sprint->closed_at = date('Y-m-d H:i:s');
        $sprint->save();

        $this->announceSprint($sprint, 'sprint.closed', $user);

        return true;
    }

    public function findLink(int $workspace, int $id): ?SprintLink
    {
        $link = SprintLink::find($id);

        return $link && $this->findSprint($workspace, $link->sprint) ? $link : null;
    }

    /**
     * @return ?SprintLink null when the sprint is closed
     */
    public function addLink(Sprint $sprint, int $user, string $url, ?string $label): ?SprintLink
    {
        if (self::SPRINT_CLOSED === $sprint->state)
            return null;

        $link = (new SprintLink([
            'sprint' => $sprint->id,
            'url' => $url,
            'label' => $label,
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();

        $this->announceSprint($sprint, 'sprint.link_added', $user, ['link' => $label ?? $url]);

        return $link;
    }

    /**
     * @return bool false when the sprint is closed
     */
    public function updateLink(SprintLink $link, int $user, string $url, ?string $label): bool
    {
        $sprint = Sprint::find($link->sprint);
        if (self::SPRINT_CLOSED === $sprint->state)
            return false;

        $link->url = $url;
        $link->label = $label;
        $link->save();

        $this->announceSprint($sprint, 'sprint.link_updated', $user, ['link' => $label ?? $url]);

        return true;
    }

    /**
     * @return bool false when the sprint is closed
     */
    public function removeLink(SprintLink $link, int $user): bool
    {
        $sprint = Sprint::find($link->sprint);
        if (self::SPRINT_CLOSED === $sprint->state)
            return false;

        SprintLink::deleteId($link->id);

        $this->announceSprint($sprint, 'sprint.link_removed', $user, ['link' => $link->label ?? $link->url]);

        return true;
    }

    private function acceptsTasks(int $workspace, ?int $sprint): bool
    {
        if (null === $sprint)
            return true;

        $found = $this->findSprint($workspace, $sprint);

        return $found && self::SPRINT_CLOSED !== $found->state;
    }

    private function nextPosition(int $workspace, ?int $sprint): int
    {
        $query = Task::select()->where('workspace', $workspace);
        null === $sprint ? $query->whereRaw('sprint IS NULL') : $query->where('sprint', $sprint);

        $last = $query->order('position', 'DESC')->first();

        return $last ? $last->position + 1 : 0;
    }

    private function sprintName(?int $sprint): ?string
    {
        return null === $sprint ? null : Sprint::find($sprint)?->name;
    }

    /**
     * @param bool $announce false inside a transaction : the caller sends the returned line once it is committed
     *
     * @return array{0: History, 1: array} The line and the payload sent to the devices
     */
    private function log(Task $task, string $type, int $user, array $details = [], bool $announce = true): array
    {
        $line = $this->history->log($task->workspace, $type, ['title' => $task->title, ...$details], user: $user, task: $task->id);
        $payload = [
            'task' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'sprint' => $task->sprint,
            ...$details,
        ];

        if ($announce)
            $this->announce($line, $payload);

        return [$line, $payload];
    }

    private function announceSprint(Sprint $sprint, string $type, int $user, array $details = []): void
    {
        $line = $this->history->log($sprint->workspace, $type, ['name' => $sprint->name, ...$details], user: $user);

        $this->announce($line, ['sprint' => $sprint->id, 'name' => $sprint->name, ...$details]);
    }

    /**
     * Announces the history line to the devices and the browsers of the workspace, as an event of the same type.
     */
    private function announce(History $line, array $payload): void
    {
        $this->announcer->announce($line->workspace, $line->id, $line->type, json_encode((object) $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
