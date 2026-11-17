<?php

namespace App\EventTypes;

interface EventTypeSource
{
    public static function name(): string;

    /**
     * @return list<string>
     */
    public function typesOf(int $workspace): array;
}
