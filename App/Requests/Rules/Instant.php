<?php

namespace App\Requests\Rules;

use Cube\Web\Http\Rules\Param;

class Instant
{
    private const FORMAT = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    public static function param(): Param
    {
        return Param::string()->withValueCondition(
            fn ($value) => 1 === preg_match(self::FORMAT, (string) $value) && false !== self::parse((string) $value),
            '{key} must be an ISO 8601 date and time with its offset (2026-12-01T09:00:00+01:00), or Y-m-d H:i:s in server time, got {value}'
        );
    }

    public static function toServerTime(string $value): string
    {
        return self::parse($value)->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    private static function parse(string $value): \DateTimeImmutable|false
    {
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return false;
        }

        // DateTimeImmutable rolls an impossible day over to the next month instead of refusing it
        return $date->format('Y-m-d') === substr($value, 0, 10) ? $date : false;
    }
}
