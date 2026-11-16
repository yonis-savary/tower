<?php

namespace App\Factories;

use App\Models\NotificationRule;
use App\Services\PushService;
use Cube\Data\Models\ModelFactory;
use Faker\Generator;

/**
 * Valid JSON in the stored fields : the OpenAPI document reads the shape of a rule from one of these.
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
            'title' => json_encode(['mode' => PushService::TEXT_RAW, 'value' => $faker->sentence(3)], JSON_THROW_ON_ERROR),
            'body' => json_encode(['mode' => PushService::TEXT_KEY, 'value' => 'author.name'], JSON_THROW_ON_ERROR),
        ];
    }
}
