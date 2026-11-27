<?php

namespace App\Domain\Search\Sources;

use App\Domain\Search\SearchResult;
use App\Domain\Search\SearchSource;
use App\Models\PullRequest;
use Cube\Data\Database\Query;
use Cube\Data\Models\Model;

class PullRequestSource extends SearchSource
{
    private const OPEN = 'OPEN';

    public static function type(): string
    {
        return 'merge_request';
    }

    public static function position(): int
    {
        return 10;
    }

    public static function section(): string
    {
        return 'merge_requests';
    }

    protected function matching(int $workspace, string $pattern): Query
    {
        return PullRequest::select()
            ->where('workspace', $workspace)
            ->whereGroup(fn (Query $query) => $query
                ->where('title', $pattern, 'LIKE')
                ->or()->where('repository', $pattern, 'LIKE')
                ->or()->where('source_branch', $pattern, 'LIKE'));
    }

    protected function restrictToDone(Query $query, bool $done): ?Query
    {
        return $query->where('state', self::OPEN, $done ? '<>' : '=');
    }

    /**
     * @param PullRequest $model
     */
    protected function toResult(Model $model): SearchResult
    {
        return new SearchResult(self::type(), $model->id, $model->title, "{$model->repository} · {$model->source_branch}", $model->url, self::OPEN !== $model->state);
    }
}
