<?php

namespace App\Domain\Task\Notes;

use App\Requests\TaskContentRequest;

class TaskNotesParser
{
    public const MAX_TASKS = 50;

    private const NOTE_PREFIX = '//';

    public function parse(string $text): TaskNotes
    {
        /** @var list<array{line: int, title: string, body: list<string>}> $blocks */
        $blocks = [];
        $errors = [];

        foreach (preg_split('/\R/u', $text) as $index => $line) {
            $lastBlock = array_key_last($blocks);

            if ('' === trim($line)) {
                if (null !== $lastBlock)
                    $blocks[$lastBlock]['body'][] = '';
                continue;
            }

            if ($this->isIndented($line)) {
                if (null !== $lastBlock)
                    $blocks[$lastBlock]['body'][] = $line;
                elseif ([] === $errors)
                    $errors[] = new NoteError($index + 1, NoteError::DESCRIPTION_WITHOUT_TASK);
                continue;
            }

            if (str_starts_with($line, self::NOTE_PREFIX))
                continue;

            $blocks[] = ['line' => $index + 1, 'title' => trim($line), 'body' => []];
        }

        $tasks = array_map(fn (array $block) => new NoteTask($block['line'], $block['title'], $this->description($block['body'])), $blocks);

        return new TaskNotes($tasks, [...$errors, ...$this->limitErrors($tasks)]);
    }

    private function isIndented(string $line): bool
    {
        return 1 === preg_match('/^[ \t]/', $line);
    }

    /**
     * @param list<string> $body
     */
    private function description(array $body): string
    {
        $lines = $this->withoutSurroundingBlanks($body);
        $indentation = $this->commonIndentation($lines);

        return implode("\n", array_map(fn (string $line) => '' === $line ? '' : substr($line, strlen($indentation)), $lines));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function withoutSurroundingBlanks(array $lines): array
    {
        while ([] !== $lines && '' === $lines[0])
            array_shift($lines);
        while ([] !== $lines && '' === $lines[array_key_last($lines)])
            array_pop($lines);

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private function commonIndentation(array $lines): string
    {
        $common = null;

        foreach ($lines as $line) {
            if ('' === $line)
                continue;

            preg_match('/^[ \t]*/', $line, $match);
            $common = null === $common ? $match[0] : $this->sharedPrefix($common, $match[0]);
        }

        return $common ?? '';
    }

    private function sharedPrefix(string $first, string $second): string
    {
        $length = 0;
        $max = min(strlen($first), strlen($second));
        while ($length < $max && $first[$length] === $second[$length])
            $length++;

        return substr($first, 0, $length);
    }

    /**
     * @param list<NoteTask> $tasks
     *
     * @return list<NoteError>
     */
    private function limitErrors(array $tasks): array
    {
        $errors = [];

        foreach ($tasks as $position => $task) {
            if (self::MAX_TASKS === $position)
                $errors[] = new NoteError($task->line, NoteError::TOO_MANY_TASKS);
            if (mb_strlen($task->title) > TaskContentRequest::MAX_TITLE)
                $errors[] = new NoteError($task->line, NoteError::TITLE_TOO_LONG);
            if (mb_strlen($task->description) > TaskContentRequest::MAX_DESCRIPTION)
                $errors[] = new NoteError($task->line, NoteError::DESCRIPTION_TOO_LONG);
        }

        return $errors;
    }
}
