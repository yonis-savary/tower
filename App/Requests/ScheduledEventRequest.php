<?php

namespace App\Requests;

use Cube\Routine\CronExpression;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\ObjectParam;
use Cube\Web\Http\Rules\Param;

class ScheduledEventRequest extends Request
{
    public function getRules(): ObjectParam
    {
        return Param::object($this->fieldRules())->withCondition(
            fn (array $values) => (null === ($values['cron'] ?? null)) !== (null === ($values['run_at'] ?? null)),
            '{key} must give either cron, for a recurring schedule, or run_at, for a one-time one'
        );
    }

    protected function fieldRules(): array
    {
        return [
            'name' => Param::string()->withValueCondition(
                fn ($name) => '' !== (string) $name && mb_strlen((string) $name) <= 100,
                '{key} must contain between 1 and 100 characters'
            ),
            ...TriggerEventRequest::eventRules(),
            'cron' => Param::string(nullable: true)->optional()->withValueCondition(
                fn ($cron) => strlen((string) $cron) <= 100 && self::isCronExpression((string) $cron),
                '{key} must be a cron expression of five fields, got {value}'
            ),
            'run_at' => Param::datetime(nullable: true)->optional()->withValueCondition(
                fn ($runAt) => substr((string) $runAt, 0, 16) > date('Y-m-d H:i'),
                '{key} must be a minute to come, got {value}'
            ),
        ];
    }

    private static function isCronExpression(string $cron): bool
    {
        try {
            new CronExpression($cron);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
