<?php

namespace App\Search;

use Cube\Data\Database\Query;
use Cube\Data\Models\Model;

abstract class SearchSource
{
    abstract public static function type(): string;

    abstract public static function position(): int;

    abstract public static function section(): string;

    abstract protected function matching(int $workspace, string $pattern): Query;

    // null : this source has nothing that can be done
    abstract protected function restrictToDone(Query $query, bool $done): ?Query;

    abstract protected function toResult(Model $model): SearchResult;

    /**
     * @return list<SearchResult>
     */
    final public function search(int $workspace, string $text, int $limit): array
    {
        $pattern = '%'.$text.'%';
        $results = [];
        foreach ([false, true] as $done) {
            $remaining = $limit - count($results);
            if ($remaining <= 0)
                break;

            $query = $this->restrictToDone($this->matching($workspace, $pattern), $done);
            if (!$query)
                continue;

            foreach ($query->order('id', 'DESC')->limit($remaining)->fetch() as $model)
                $results[] = $this->toResult($model);
        }

        return $results;
    }
}
