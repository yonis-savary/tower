<?php

namespace App\Requests;

use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\ObjectParam;
use Cube\Web\Http\Rules\Param;

/**
 * A period of whole days, both included, in server time.
 */
class TimelineRequest extends Request
{
    public const MAX_DAYS = 92;

    public function getRules(): ObjectParam
    {
        return Param::object([
            'from' => Param::date(),
            'to' => Param::date(),
        ])->withCondition(
            fn (array $values) => !isset($values['from'], $values['to']) || self::isRange($values['from'], $values['to']),
            '{key} must give a `to` on or after `from`, at most '.self::MAX_DAYS.' days later'
        );
    }

    private static function isRange(string $from, string $to): bool
    {
        return $from <= $to && (new \DateTimeImmutable($from))->modify('+'.self::MAX_DAYS.' days')->format('Y-m-d') >= $to;
    }

    /** The first second of the period */
    public function start(): string
    {
        return $this->validated('from').' 00:00:00';
    }

    /** The last second of the period */
    public function end(): string
    {
        return $this->validated('to').' 23:59:59';
    }
}
