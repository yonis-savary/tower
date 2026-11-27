<?php

namespace App\Requests;

use App\Requests\Rules\Instant;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\ObjectParam;
use Cube\Web\Http\Rules\Param;

class AgendaRangeRequest extends Request
{
    use ValidatesOnce;

    public const MAX_DAYS = 62;

    public function getRules(): ObjectParam
    {
        return Param::object($this->fieldRules())->withCondition(
            fn (array $values) => !isset($values['from'], $values['to'])
                || self::isRange(Instant::toServerTime($values['from']), Instant::toServerTime($values['to'])),
            '{key} must give a `to` after `from`, at most '.self::MAX_DAYS.' days later'
        );
    }

    protected function fieldRules(): array
    {
        return [
            'from' => Instant::param(),
            'to' => Instant::param(),
            'workspaces' => self::idList('workspace'),
        ];
    }

    // A comma-separated list : the generated client would repeat the key of an array, which PHP reads as its last value
    protected static function idList(string $of): Param
    {
        return Param::string()->optional()->withValueCondition(
            fn ($ids) => 1 === preg_match('/^\d+(,\d+){0,49}$/', (string) $ids),
            "{key} must list {$of} ids separated by commas, got {value}"
        );
    }

    private static function isRange(string $from, string $to): bool
    {
        return $from < $to && (new \DateTimeImmutable($from))->modify('+'.self::MAX_DAYS.' days')->format('Y-m-d H:i:s') >= $to;
    }

    public function from(): string
    {
        return Instant::toServerTime($this->validatedValues()['from']);
    }

    public function to(): string
    {
        return Instant::toServerTime($this->validatedValues()['to']);
    }

    /**
     * @return ?list<int>
     */
    public function workspaces(): ?array
    {
        return $this->ids('workspaces');
    }

    /**
     * @return ?list<int>
     */
    protected function ids(string $key): ?array
    {
        $ids = $this->validatedValues()[$key] ?? null;

        return null === $ids ? null : array_values(array_unique(array_map('intval', explode(',', $ids))));
    }
}
