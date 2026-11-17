<?php

namespace App\EventTypes\Sources;

use App\EventTypes\EventTypeSource;
use App\Models\QuickAction;

class QuickActionTypes implements EventTypeSource
{
    public static function name(): string
    {
        return 'quick_action';
    }

    public function typesOf(int $workspace): array
    {
        return array_map(fn (QuickAction $action) => $action->type, QuickAction::select()->where('workspace', $workspace)->fetch());
    }
}
