<?php

namespace App\Services;

use App\Resources\AtomDate;

/**
 * Places a task or an issue on a period from its status changes, each one classified as `todo`,
 * `in_progress` or `done`. Waiting in a to do status is not working on it : the span starts when
 * the item first leaves it.
 */
final class TimelineSpan
{
    public const TODO = 'todo';
    public const IN_PROGRESS = 'in_progress';
    public const DONE = 'done';

    /**
     * @param list<array{at:string,from:?string,to:?string,from_category:string,to_category:string}> $changes every known status change, oldest first, `at` readable by DateTimeImmutable
     * @param string $category the category of the current status
     * @param ?string $created when the item was created
     * @param ?string $ended when it ended, used when no change says it
     * @param int $start first second of the period
     * @param int $end last second of the period
     *
     * @return ?array The placement, dates in ISO 8601, null when the item was not worked on during the period
     */
    public static function place(array $changes, string $category, ?string $created, ?string $ended, int $start, int $end): ?array
    {
        $time = fn (?string $date) => null === $date ? null : (new \DateTimeImmutable($date))->getTimestamp();

        $leaving = array_values(array_filter($changes, fn (array $change) => self::TODO !== $change['to_category']));
        $startedAt = $leaving[0]['at'] ?? (self::TODO === $category ? null : $created);

        $finishing = array_values(array_filter($changes, fn (array $change) => self::DONE === $change['to_category']));
        $endedAt = self::DONE === $category ? (end($finishing)['at'] ?? null) ?? $ended : null;

        $inPeriod = array_values(array_filter($changes, fn (array $change) => $time($change['at']) >= $start && $time($change['at']) <= $end));
        $initial = $inPeriod[0]['from_category'] ?? $category;

        $endedInPeriod = null !== $endedAt && $time($endedAt) >= $start && $time($endedAt) <= $end;
        $inProgressAtStart = self::IN_PROGRESS === $initial && (null === $startedAt || $time($startedAt) <= $end);
        $startedInPeriod = [] !== array_filter($inPeriod, fn (array $change) => self::IN_PROGRESS === $change['to_category']);
        if (!$endedInPeriod && !$inProgressAtStart && !$startedInPeriod)
            return null;

        return [
            'created_at' => AtomDate::format($created),
            'started_at' => AtomDate::format($startedAt),
            'ended_at' => AtomDate::format($endedAt),
            'initial_category' => $initial,
            'changes' => array_map(fn (array $change) => [...$change, 'at' => AtomDate::format($change['at'])], $inPeriod),
        ];
    }
}
