<?php

namespace App\Search\Sources;

use App\Models\Task;
use App\Search\SearchResult;
use App\Search\SearchSource;
use App\Services\TaskService;
use Cube\Data\Database\Query;
use Cube\Data\Models\Model;

class TaskSource extends SearchSource
{
    private const FINISHED = [TaskService::STATUS_DONE, 'cancelled'];

    public static function type(): string
    {
        return 'task';
    }

    public static function position(): int
    {
        return 30;
    }

    public static function section(): string
    {
        return 'tasks';
    }

    protected function matching(int $workspace, string $pattern): Query
    {
        return Task::select()
            ->where('workspace', $workspace)
            ->whereGroup(fn (Query $query) => $query
                ->where('title', $pattern, 'LIKE')
                ->or()->where('description', $pattern, 'LIKE'));
    }

    protected function restrictToDone(Query $query, bool $done): ?Query
    {
        return $done ? $query->whereIn('status', self::FINISHED) : $query->whereNotIn('status', self::FINISHED);
    }

    /**
     * @param Task $model
     */
    protected function toResult(Model $model): SearchResult
    {
        return new SearchResult(self::type(), $model->id, $model->title, $model->status, null, in_array($model->status, self::FINISHED, true));
    }
}
