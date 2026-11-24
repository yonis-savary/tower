<?php

namespace App\Requests;

use App\Requests\Rules\HexColor;
use App\Requests\Rules\Instant;
use App\Requests\Rules\WebAddress;
use Cube\Web\Http\Request;
use Cube\Web\Http\Rules\ObjectParam;
use Cube\Web\Http\Rules\Param;

class AgendaItemRequest extends Request
{
    public const MAX_PARTICIPANTS = 50;

    public function getRules(): ObjectParam
    {
        return Param::object($this->fieldRules())->withCondition(
            fn (array $values) => !isset($values['starts_at'], $values['ends_at']) || Instant::toServerTime($values['starts_at']) < Instant::toServerTime($values['ends_at']),
            '{key} must end after it starts'
        );
    }

    protected function fieldRules(): array
    {
        return [
            'title' => Param::string()->withValueCondition(
                fn ($title) => '' !== (string) $title && mb_strlen((string) $title) <= 255,
                '{key} must contain between 1 and 255 characters'
            ),
            'description' => Param::string(nullable: true)->optional()->withValueCondition(
                fn ($description) => mb_strlen((string) $description) <= 20000,
                '{key} must contain at most 20000 characters'
            ),
            'starts_at' => Instant::param(),
            'ends_at' => Instant::param(),
            'all_day' => Param::boolean()->optional(),
            'link' => WebAddress::param(nullable: true)->optional(),
            'color' => HexColor::param()->optional(),
            'participants' => Param::array(Param::integer())->optional()->withValueCondition(
                fn ($participants) => count((array) $participants) <= self::MAX_PARTICIPANTS,
                '{key} must list at most '.self::MAX_PARTICIPANTS.' users'
            ),
        ];
    }
}
