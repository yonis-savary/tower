<?php

namespace App\Search\Sources;

use App\Models\DocPage;
use App\Search\SearchResult;
use App\Search\SearchSource;
use Cube\Data\Database\Query;
use Cube\Data\Models\Model;

class DocPageSource extends SearchSource
{
    public static function type(): string
    {
        return 'doc_page';
    }

    public static function position(): int
    {
        return 60;
    }

    public static function section(): string
    {
        return 'documentation';
    }

    protected function matching(int $workspace, string $pattern): Query
    {
        return DocPage::select()
            ->where('workspace', $workspace)
            ->whereGroup(fn (Query $query) => $query
                ->where('title', $pattern, 'LIKE')
                ->or()->where('content', $pattern, 'LIKE'));
    }

    protected function restrictToDone(Query $query, bool $done): ?Query
    {
        return $query->where('archived_at', null, $done ? '<>' : '=');
    }

    /**
     * @param DocPage $model
     */
    protected function toResult(Model $model): SearchResult
    {
        return new SearchResult(self::type(), $model->id, $model->title, null, null, null !== $model->archived_at);
    }
}
