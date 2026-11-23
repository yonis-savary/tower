<?php

namespace App\TaskNotes;

class NoteTask
{
    public function __construct(
        public readonly int $line,
        public readonly string $title,
        public readonly string $description,
    ) {}
}
