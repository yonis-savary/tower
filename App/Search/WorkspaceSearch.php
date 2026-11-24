<?php

namespace App\Search;

use App\Models\Workspace;
use App\Services\WorkspaceService;
use Cube\Data\Bunch;

class WorkspaceSearch
{
    public const LIMIT_PER_TYPE = 5;

    /**
     * @return list<SearchResult>
     */
    public function search(Workspace $workspace, string $text): array
    {
        $sections = WorkspaceService::sectionsOf($workspace);

        return Bunch::fromExtends(SearchSource::class)
            ->filter(fn (SearchSource $source) => in_array($source::section(), $sections, true))
            ->sort(fn (SearchSource $source) => $source::position())
            ->map(fn (SearchSource $source) => $source->search($workspace->id, $text, self::LIMIT_PER_TYPE))
            ->reduce(fn (array $results, array $found) => [...$results, ...$found], []);
    }
}
