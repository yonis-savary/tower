<?php

namespace App\Agenda;

final class ScheduleOccurrence
{
    public function __construct(
        public readonly int $schedule,
        public readonly int $workspace,
        public readonly string $name,
        public readonly string $type,
        public readonly \DateTimeImmutable $at,
        public readonly bool $frequent,
        public readonly int $runs,
    ) {}

    /**
     * @return array{schedule: int, workspace: int, name: string, type: string, at: string, frequent: bool, runs: int}
     */
    public function toArray(): array
    {
        return [
            'schedule' => $this->schedule,
            'workspace' => $this->workspace,
            'name' => $this->name,
            'type' => $this->type,
            'at' => $this->at->format(\DateTimeInterface::ATOM),
            'frequent' => $this->frequent,
            'runs' => $this->runs,
        ];
    }
}
