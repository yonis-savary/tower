<?php

namespace App\Domain\Event\Types;

interface EventTypeSource
{
    public static function name(): string;

    /**
     * @return list<string>
     */
    public function typesOf(int $workspace): array;
}
