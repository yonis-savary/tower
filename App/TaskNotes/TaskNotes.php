<?php

namespace App\TaskNotes;

class TaskNotes
{
    /**
     * @param list<NoteTask>  $tasks
     * @param list<NoteError> $errors
     */
    public function __construct(
        public readonly array $tasks,
        public readonly array $errors,
    ) {}

    public function isCreatable(): bool
    {
        return [] !== $this->tasks && [] === $this->errors;
    }

    /**
     * @return array{tasks: list<array{line: int, title: string, description: string}>, errors: list<array{line: int, error: string}>}
     */
    public function toArray(): array
    {
        return [
            'tasks' => array_map(fn (NoteTask $task) => ['line' => $task->line, 'title' => $task->title, 'description' => $task->description], $this->tasks),
            'errors' => array_map(fn (NoteError $error) => ['line' => $error->line, 'error' => $error->error], $this->errors),
        ];
    }
}
