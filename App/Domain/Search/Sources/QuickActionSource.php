<?php

namespace App\Domain\Search\Sources;

use App\Domain\Search\SearchResult;
use App\Domain\Search\SearchSource;
use App\Models\QuickAction;
use Cube\Data\Database\Query;
use Cube\Data\Models\Model;

class QuickActionSource extends SearchSource
{
    public static function type(): string
    {
        return 'quick_action';
    }

    public static function position(): int
    {
        return 50;
    }

    public static function section(): string
    {
        return 'events';
    }

    protected function matching(int $workspace, string $pattern): Query
    {
        return QuickAction::select()
            ->where('workspace', $workspace)
            ->whereGroup(fn (Query $query) => $query
                ->where('name', $pattern, 'LIKE')
                ->or()->where('type', $pattern, 'LIKE')
                ->or()->where('description', $pattern, 'LIKE'));
    }

    protected function restrictToDone(Query $query, bool $done): ?Query
    {
        return $done ? null : $query;
    }

    /**
     * @param QuickAction $model
     */
    protected function toResult(Model $model): SearchResult
    {
        return new SearchResult(self::type(), $model->id, $model->name, $model->type, null, false);
    }
}
