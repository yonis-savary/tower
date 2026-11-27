<?php

namespace App\Domain\Event\Types\Sources;

use App\Domain\Event\EventService;
use App\Domain\Event\Types\EventTypeSource;
use App\Models\History;

class RecentlyTriggeredTypes implements EventTypeSource
{
    public const LINES = 200;

    public static function name(): string
    {
        return 'history';
    }

    public function typesOf(int $workspace): array
    {
        $lines = History::select()
            ->where('workspace', $workspace)
            ->where('type', EventService::HISTORY_TYPE)
            ->order('id', 'DESC')
            ->limit(self::LINES)
            ->fetch();

        return array_values(array_filter(array_map(fn (History $line) => json_decode((string) $line->details, true)['event'] ?? null, $lines), 'is_string'));
    }
}
