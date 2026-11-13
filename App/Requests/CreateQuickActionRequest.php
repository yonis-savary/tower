<?php

namespace App\Requests;

use Cube\Web\Http\Rules\Param;

class CreateQuickActionRequest extends TriggerEventRequest
{
    public function getRules(): array
    {
        return [
            'name' => Param::string()->withValueCondition(
                fn ($name) => '' !== (string) $name && mb_strlen((string) $name) <= 100,
                '{key} must contain between 1 and 100 characters'
            ),
            'description' => Param::string(nullable: true)->withValueCondition(
                fn ($description) => null === $description || mb_strlen((string) $description) <= 255,
                '{key} must contain at most 255 characters'
            )->optional(),
            ...parent::getRules(),
        ];
    }
}
