<?php

namespace App\Factories;

use App\Models\NotificationRule;
use Cube\Data\Models\ModelFactory;
use Faker\Generator;

/**
 * Valid JSON in the listen field : the OpenAPI document reads the shape of a rule from one of these.
 *
 * @extends ModelFactory<NotificationRule>
 */
class NotificationRuleFactory extends ModelFactory
{
    public static function getModelClass(): string
    {
        return NotificationRule::class;
    }

    protected function definition(Generator $faker): array
    {
        return [
            'listen' => json_encode(['task.*', 'pr.pipeline.failed'], JSON_THROW_ON_ERROR),
            'title' => 'Pipeline {payload.status}',
            'body' => '{payload.title} by {payload.author.name}',
        ];
    }
}
