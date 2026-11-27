<?php

namespace App\Domain\Task\Notes;

class NoteError
{
    public const TITLE_TOO_LONG = 'title_too_long';
    public const DESCRIPTION_TOO_LONG = 'description_too_long';
    public const DESCRIPTION_WITHOUT_TASK = 'description_without_task';
    public const TOO_MANY_TASKS = 'too_many_tasks';

    public function __construct(
        public readonly int $line,
        public readonly string $error,
    ) {}
}
