<?php

namespace App\Domain\Search\Sources;

use App\Domain\Integrations\Jira\JiraIssueData;
use App\Domain\Search\SearchResult;
use App\Domain\Search\SearchSource;
use App\Models\JiraIssue;
use Cube\Data\Database\Query;
use Cube\Data\Models\Model;

class JiraIssueSource extends SearchSource
{
    public static function type(): string
    {
        return 'jira_issue';
    }

    public static function position(): int
    {
        return 20;
    }

    public static function section(): string
    {
        return 'jira';
    }

    protected function matching(int $workspace, string $pattern): Query
    {
        return JiraIssue::select()
            ->where('workspace', $workspace)
            ->whereGroup(fn (Query $query) => $query
                ->where('issue_key', $pattern, 'LIKE')
                ->or()->where('summary', $pattern, 'LIKE'));
    }

    // An issue no profile tracks any more is only kept for the task linked to it : it counts as done
    protected function restrictToDone(Query $query, bool $done): ?Query
    {
        if (!$done)
            return $query->where('tracked', true)->where('status_category', JiraIssueData::CATEGORY_DONE, '<>');

        return $query->whereGroup(fn (Query $group) => $group
            ->where('tracked', false)
            ->or()->where('status_category', JiraIssueData::CATEGORY_DONE));
    }

    /**
     * @param JiraIssue $model
     */
    protected function toResult(Model $model): SearchResult
    {
        $done = !$model->tracked || JiraIssueData::CATEGORY_DONE === $model->status_category;

        return new SearchResult(self::type(), $model->id, $model->summary, "{$model->issue_key} · {$model->status}", $model->url, $done);
    }
}
