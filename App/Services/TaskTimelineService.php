<?php

namespace App\Services;

use App\Models\History;
use App\Models\Task;
use Cube\Data\Bunch;

/**
 * The tasks worked on during a period. History is kept 31 days : before, a task only has its creation
 * and its completion to place it.
 */
class TaskTimelineService
{
    private const STATUS_CHANGED = 'task.status_changed';
    private const CATEGORIES = [
        TaskService::STATUS_TODO => TimelineSpan::TODO,
        TaskService::STATUS_DONE => TimelineSpan::DONE,
        'cancelled' => TimelineSpan::DONE,
    ];

    /**
     * @param string $start first second of the period, Y-m-d H:i:s in server time
     * @param string $end last second of the period, Y-m-d H:i:s in server time
     *
     * @return list<array> Oldest start first
     */
    public function timeline(int $workspace, string $start, string $end): array
    {
        $changes = $this->statusChanges($workspace);
        $from = strtotime($start);
        $to = strtotime($end);

        return Bunch::wrap(Task::select(['_sprint'])->where('workspace', $workspace)->fetch())
            ->map(fn (Task $task) => $this->entry($task, $changes[$task->id] ?? [], $from, $to))
            ->filter()
            ->sort(fn (array $entry) => [$entry['started_at'] ?? $entry['created_at'] ?? '', $entry['id']])
            ->values()
            ->toArray();
    }

    public static function categoryOf(?string $status): string
    {
        return self::CATEGORIES[$status] ?? TimelineSpan::IN_PROGRESS;
    }

    private function entry(Task $task, array $changes, int $start, int $end): ?array
    {
        $ended = self::stamp('cancelled' === $task->status ? $task->updated_at : $task->done_at);
        $span = TimelineSpan::place($changes, self::categoryOf($task->status), self::stamp($task->created_at), $ended, $start, $end);
        if (!$span)
            return null;

        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'status_category' => self::categoryOf($task->status),
            'sprint' => $task->sprint,
            'sprint_name' => $task->_sprint?->name,
            'jira_issue' => $task->jira_issue,
            'link' => $task->link,
            ...$span,
        ];
    }

    /**
     * @return array<int,list<array>> By task, oldest first
     */
    private function statusChanges(int $workspace): array
    {
        $changes = [];
        $lines = History::select()->where('workspace', $workspace)->where('type', self::STATUS_CHANGED)->order('id', 'ASC')->fetch();

        foreach ($lines as $line) {
            if (null === $line->task)
                continue;

            $details = json_decode((string) $line->details, true) ?: [];
            $changes[$line->task][] = [
                'at' => self::stamp($line->created_at),
                'from' => $details['from'] ?? null,
                'to' => $details['to'] ?? null,
                'from_category' => self::categoryOf($details['from'] ?? null),
                'to_category' => self::categoryOf($details['to'] ?? null),
            ];
        }

        return $changes;
    }

    private static function stamp(\DateTimeInterface|string|null $date): ?string
    {
        if (null === $date)
            return null;

        return ($date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable($date))->format('Y-m-d H:i:s');
    }
}
